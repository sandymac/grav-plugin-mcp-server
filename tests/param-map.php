<?php

declare(strict_types=1);

/**
 * Cross-references every MCP tool's outgoing params against grav-plugin-api's
 * real route table and controller source.
 *
 * Every tool is a param mapping onto an in-process REST call, so a param sent
 * under a name the controller never reads fails silently — five such bugs
 * shipped before this existed (search_packages sent `query`, GpmController
 * reads `q`). So: run every handler against a recording ApiBridge, then check
 * what it recorded against the api plugin's addRoute() table and the matched
 * controller method's own source.
 *
 *   docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/param-map.php
 *   API_PLUGIN_DIR=/tmp/grav-plugin-api php tests/param-map.php
 *
 * The api plugin is read as TEXT, never loaded — no Grav, no vendor, no autoload.
 */

namespace Nyholm\Psr7 {
    // ponytail: MediaTools news these up inside its upload handlers and vendor/
    // isn't here. Uploaded files are never asserted on (the api plugin's
    // HandlesMediaUploads flattens any field name), so stubs that don't crash
    // are the whole requirement.
    if (!class_exists(Stream::class, false)) {
        class Stream
        {
            public static function create(mixed $body = ''): self
            {
                return new self();
            }
        }
    }
    if (!class_exists(UploadedFile::class, false)) {
        class UploadedFile
        {
            public function __construct(mixed ...$args)
            {
            }
        }
    }
}

namespace {

/**
 * Consciously-accepted extras: tool name => param names never flagged.
 * Empty on purpose — an entry here is a documented decision to send something
 * the controller ignores, not a place to silence a fresh failure.
 */
$allow = [];

/**
 * Permission-contract exceptions: tool name => the declared permission we stand
 * behind, for routes whose enforcement is argument- or identity-dependent (the
 * extractor reports nothing, DYNAMIC, or several strings). An entry is a
 * reviewed decision with a reason on its line, not a mute button.
 */
$permissionPolicy = [
    // UsersController::index branches instead of rejecting: without api.users.read
    // the listing auto-filters to self; show allows self-access with just
    // api.access. Advertised contract is the general (any-user) read.
    'get_users' => 'api.users.read',
    // update allows self-access with just api.access; the declaration
    // advertises the general (any-user) write contract.
    'manage_users' => 'api.users.write',
    // requireApiKeyPermission(): own keys need only the authenticated account,
    // other users' keys need api.users.write. Advertise the general contract.
    'manage_api_keys' => 'api.users.write',
    // PasswordPolicyController::show enforces nothing — public by design.
    'get_password_policy' => null,
    // Five routes, three permissions by blueprint type; declared api.access (the
    // weakest) so any key that can use some variant sees the tool.
    'get_blueprint' => 'api.access',
];

$apiDir = getenv('API_PLUGIN_DIR') ?: __DIR__ . '/../.gravtest/grav-admin/user/plugins/api';
if (!is_file($apiDir . '/classes/Api/ApiRouter.php')) {
    echo "param-map: SKIP (no api plugin source; set API_PLUGIN_DIR)\n";
    exit(0);
}

require __DIR__ . '/../classes/ApiBridge.php';
foreach (glob(__DIR__ . '/../classes/Tools/*.php') as $domainFile) {
    require $domainFile;
}
require __DIR__ . '/../classes/ToolRegistry.php';
require __DIR__ . '/../classes/McpServer.php'; // site_info's handler reads McpServer::VERSION

/** Captures what a handler asks for and answers blandly, so handlers run to completion. */
final class RecordingBridge extends Grav\Plugin\McpServer\ApiBridge
{
    /** @var list<array{method: string, path: string, query: list<string>, body: ?list<string>}> */
    public array $calls = [];

    public function __construct()
    {
        // Deliberately not parent::__construct(): no Grav, no key, nothing dispatched.
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null, array $headers = [], array $files = []): array
    {
        $queryKeys = [];
        foreach ($query as $name => $value) {
            // Mirrors ApiBridge::request()'s normalization — a dropped key was never sent.
            if ($value !== null && $value !== '') {
                $queryKeys[] = (string) $name;
            }
        }

        $this->calls[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'query' => $queryKeys,
            'body' => $body === null ? null : array_map('strval', array_keys($body)),
        ];

        return ['status' => 200, 'headers' => [], 'json' => ['data' => []]];
    }
}

// --- Truth side: the api plugin's route table -------------------------------

/** `{name}` -> any segment, `{name:regex}` -> the declared regex. */
function pathRegex(string $path): string
{
    $regex = '';
    // ponytail: `[^}]+` for the inline regex means a placeholder whose own regex
    // contains `}` (e.g. `{n:\d{2,}}`) would mis-parse. None do; fix if one lands.
    foreach (preg_split('/(\{\w+(?::[^}]+)?\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        if ($part === '') {
            continue;
        }
        if ($part[0] === '{') {
            $inner = substr($part, 1, -1);
            $colon = strpos($inner, ':');
            $regex .= '(?:' . ($colon === false ? '[^/]+' : substr($inner, $colon + 1)) . ')';
        } else {
            $regex .= preg_quote($part, '#');
        }
    }

    return '#^' . $regex . '$#';
}

/** @return list<string> */
function phpFiles(string $dir): array
{
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $out[] = (string) $file;
        }
    }
    sort($out);

    return $out;
}

/** @return list<array{method: string, path: string, controller: string, action: string, static: bool, regex: string}> */
function collectRoutes(string $dir): array
{
    $routes = [];
    foreach (phpFiles($dir) as $file) {
        preg_match_all(
            '/addRoute\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*\[\s*(\w+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]/',
            (string) file_get_contents($file),
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $routes[] = [
                'method' => strtoupper($m[1]),
                'path' => $m[2],
                'controller' => $m[3],
                'action' => $m[4],
                'static' => !str_contains($m[2], '{'),
                'regex' => pathRegex($m[2]),
            ];
        }
    }

    return $routes;
}

/** FastRoute's own precedence: the static map first, then variable routes in declaration order. */
function matchRoute(array $routes, string $method, string $path): ?array
{
    foreach ($routes as $route) {
        if ($route['static'] && $route['method'] === $method && $route['path'] === $path) {
            return $route;
        }
    }
    foreach ($routes as $route) {
        if (!$route['static'] && $route['method'] === $method && preg_match($route['regex'], $path) === 1) {
            return $route;
        }
    }

    return null;
}

// --- Truth side: what a controller method actually reads --------------------

/**
 * Source text of one method, brace-counted over PHP tokens.
 *
 * Token-level counting (not char-level) is what makes this safe —
 * braces inside strings and comments arrive as one token and never count.
 * T_CURLY_OPEN/T_DOLLAR_OPEN_CURLY_BRACES are interpolation opens whose `}` is
 * a bare token, so they must count as opens to stay balanced.
 */
function fileSource(string $file): string
{
    static $cache = [];

    return $cache[$file] ??= (string) file_get_contents($file);
}

function methodSource(string $file, string $method): ?string
{
    static $cache = [];
    if (array_key_exists($file . '::' . $method, $cache)) {
        return $cache[$file . '::' . $method];
    }
    $cache[$file . '::' . $method] = null;

    $tokens = token_get_all(fileSource($file));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $method) {
            continue;
        }

        $started = false;
        $depth = 0;
        $src = '';
        for ($k = $j; $k < $count; $k++) {
            $token = $tokens[$k];
            $text = is_array($token) ? $token[1] : $token;
            $isOpen = $text === '{'
                || (is_array($token) && ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES));

            if (!$started) {
                if ($text === ';') {
                    return null; // abstract or interface method: no body
                }
                if (!$isOpen) {
                    continue;
                }
                $started = true;
            }

            $src .= $text;
            if ($isOpen) {
                $depth++;
            } elseif ($text === '}' && --$depth === 0) {
                return $cache[$file . '::' . $method] = $src;
            }
        }
    }

    return null;
}

/** Name of the function whose argument list encloses $pos, or '' if none. */
function enclosingCall(string $src, int $pos): string
{
    $depth = 0;
    for ($i = $pos - 1; $i >= 0; $i--) {
        if ($src[$i] === ')') {
            $depth++;
        } elseif ($src[$i] === '(') {
            if ($depth === 0) {
                return preg_match('/([A-Za-z_]\w*)\s*$/', substr($src, 0, $i), $m) === 1 ? $m[1] : '';
            }
            $depth--;
        }
    }

    return '';
}

/**
 * True if any tracked variable is handed off whole instead of read key-by-key —
 * then we cannot know which keys are consumed and must not assert on them.
 */
function isOpaque(string $src, array $vars): bool
{
    // Passing the array to one of these reads no keys, so it stays transparent.
    $safe = ['requireFields', 'isset', 'empty', 'array_key_exists', 'count', 'is_array', 'array_keys', 'foreach', 'if', 'elseif', 'while', 'switch'];

    foreach ($vars as $var) {
        preg_match_all('/\$' . preg_quote($var, '/') . '\b/', $src, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$text, $pos]) {
            $after = substr($src, $pos + strlen($text));
            // A key read, an assignment, a comparison or a null-coalesce reads no whole array.
            if (preg_match('/^\s*(\[|=[^=>]|\?\?|[!=]==?)/', $after) === 1) {
                continue;
            }
            if (!in_array(enclosingCall($src, $pos), $safe, true)) {
                return true;
            }
        }
    }

    return false;
}

/** @return list<string> the quoted keys read off any of $vars, plus $literal['k'] reads */
function keysRead(string $src, array $vars): array
{
    $keys = [];
    foreach ($vars as $var) {
        preg_match_all('/\$' . preg_quote($var, '/') . '\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $src, $m);
        $keys = array_merge($keys, $m[1]);
    }

    return $keys;
}

/** Value of a scalar `const NAME = 'value';` in $file, null if absent. */
function constScalar(string $file, string $name): ?string
{
    return preg_match('/const\s+(?:[\w|?\\\\]+\s+)?' . preg_quote($name, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]/', fileSource($file), $m) === 1 ? $m[1] : null;
}

// --- Truth side: what permission a controller action enforces ---------------

/**
 * Distinct permission strings the routed action's own body enforces.
 * Resolves requirePermission() literals and self::CONSTs, requireSuper()
 * (= the 'admin.super' scope cap), and the trailing permission argument of
 * authorizePageAction(). Anything else becomes 'DYNAMIC', which forces an
 * explicit $permissionPolicy entry instead of a silent pass.
 *
 * @return list<string>
 */
function enforcedPermissions(string $file, string $action): array
{
    $src = methodSource($file, $action);
    if ($src === null) {
        return [];
    }

    $out = [];
    if (str_contains($src, '$this->requireSuper(')) {
        $out[] = 'admin.super';
    }

    preg_match_all('/\$this->requirePermission\(\s*\$request\s*,\s*([^)]+?)\s*\)/', $src, $m);
    foreach ($m[1] as $arg) {
        if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', $arg, $lit) === 1) {
            $out[] = $lit[1];
        } elseif (preg_match('/^self::(\w+)$/', $arg, $const) === 1) {
            $out[] = constScalar($file, $const[1]) ?? 'DYNAMIC';
        } else {
            $out[] = 'DYNAMIC';
        }
    }

    // authorizePageAction($request, $page, 'action', PERMISSION): the last
    // self::CONST or quoted string in the argument list is the fallback
    // permission the page ACL substitutes for.
    preg_match_all('/\$this->authorizePageAction\(([^;]+?)\);/s', $src, $m);
    foreach ($m[1] as $args) {
        if (preg_match_all('/self::(\w+)|[\'"](api\.[\w.]+)[\'"]/', $args, $parts, PREG_SET_ORDER) > 0) {
            $last = end($parts);
            $out[] = isset($last[2]) && $last[2] !== '' ? $last[2] : (constScalar($file, $last[1]) ?? 'DYNAMIC');
        } else {
            $out[] = 'DYNAMIC';
        }
    }

    return array_values(array_unique($out));
}

/** Values of a `const NAME = ['a', 'b'];` array literal in $file. */
function constArray(string $file, string $name): array
{
    if (preg_match('/const\s+(?:[\w|?\\\\]+\s+)?' . preg_quote($name, '/') . '\s*=\s*\[([^\]]*)\]/', fileSource($file), $m) !== 1) {
        return [];
    }
    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $values);

    return $values[1];
}

/**
 * What one method body itself reads off the query string and the JSON body.
 *
 * @return array{query: list<string>, body: list<string>, required: list<string>, hasQuery: bool, hasBody: bool, queryHandedOff: bool, bodyHandedOff: bool, helpers: list<string>}
 */
function readSets(string $src, string $file): array
{
    $queryVars = [];
    $bodyVars = [];

    preg_match_all('/\$(\w+)\s*=\s*\$request->getQueryParams\(\)\s*;/', $src, $m);
    $queryVars = array_merge($queryVars, $m[1]);

    foreach ([
        '/\$(\w+)\s*=\s*\$this->getRequestBody\(\s*\$request\s*\)\s*;/',
        '/\$(\w+)\s*=\s*\$request->getParsedBody\(\)\s*;/',
        '/\$(\w+)\s*=\s*\$request->getAttribute\(\s*[\'"]json_body[\'"]\s*\)/',
        '/\$(\w+)\s*=\s*json_decode\([^;]*getBody\(/',
    ] as $pattern) {
        preg_match_all($pattern, $src, $m);
        $bodyVars = array_merge($bodyVars, $m[1]);
    }

    // Conventional names, counted only when they're actually indexed here.
    if (preg_match('/\$query\s*\[\s*[\'"]/', $src) === 1) {
        $queryVars[] = 'query';
    }
    if (preg_match('/\$body\s*\[\s*[\'"]/', $src) === 1) {
        $bodyVars[] = 'body';
    }
    $queryVars = array_values(array_unique($queryVars));
    $bodyVars = array_values(array_unique($bodyVars));

    $query = keysRead($src, $queryVars);
    $body = keysRead($src, $bodyVars);

    // Inline forms that never land in a variable.
    preg_match_all('/getQueryParams\(\)\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $src, $m);
    $query = array_merge($query, $m[1]);
    preg_match_all('/getRequestBody\(\s*\$request\s*\)\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $src, $m);
    $body = array_merge($body, $m[1]);

    // The framework's declared-param helpers. Their allowed keys are the constant
    // at the call site, so resolve them here instead of chasing into getFilters(),
    // which reads `$query[$variable]` and would look opaque.
    $declared = str_contains($src, '$this->getPagination(');
    if ($declared) {
        $query = array_merge($query, ['page', 'per_page']);
    }
    if (preg_match('/\$this->getSorting\(\s*\$request\s*,\s*self::(\w+)\)/', $src) === 1) {
        $query = array_merge($query, ['sort', 'order']);
        $declared = true;
    }
    preg_match_all('/\$this->getFilters\(\s*\$request\s*,\s*self::(\w+)\)/', $src, $m);
    foreach ($m[1] as $const) {
        $query = array_merge($query, constArray($file, $const));
        $declared = true;
    }

    // requireFields($body, ['a', 'b']) is both a read and a hard requirement.
    $required = [];
    preg_match_all('/\$this->requireFields\(\s*\$(\w+)\s*,\s*\[([^\]]*)\]/', $src, $m, PREG_SET_ORDER);
    foreach ($m as $call) {
        if (!in_array($call[1], $bodyVars, true)) {
            continue; // requireFields on a nested item, not on the body itself
        }
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $call[2], $fields);
        $required = array_merge($required, $fields[1]);
        $body = array_merge($body, $fields[1]);
    }

    // Delegation: only calls whose FIRST argument is $request can be reading
    // params on this method's behalf, which conveniently excludes the
    // ($thing, $request) authorization helpers. The accessors modelled above are
    // excluded too — getRequestBody() returns the array wholesale by design, so
    // chasing it would mark every single caller's body opaque.
    preg_match_all('/\$this->(\w+)\(\s*\$request\b/', $src, $m);
    $helpers = array_values(array_diff(array_unique($m[1]), ['getPagination', 'getSorting', 'getFilters', 'getRequestBody']));

    return [
        'query' => array_values(array_unique($query)),
        'body' => array_values(array_unique($body)),
        'required' => array_values(array_unique($required)),
        'hasQuery' => $queryVars !== [] || $declared,
        'hasBody' => $bodyVars !== [],
        'queryHandedOff' => isOpaque($src, $queryVars),
        'bodyHandedOff' => isOpaque($src, $bodyVars),
        'helpers' => $helpers,
    ];
}

/** Files under classes/Api/ that declare `function $method(`, $preferFile winning outright. */
function declaringFiles(string $apiDir, string $method, ?string $preferFile): array
{
    $needle = 'function ' . $method . '(';
    if ($preferFile !== null && str_contains(fileSource($preferFile), $needle)) {
        return [$preferFile];
    }

    return array_values(array_filter(
        phpFiles($apiDir . '/classes/Api'),
        static fn(string $file): bool => str_contains(fileSource($file), $needle)
    ));
}

/**
 * Merged read picture for a method plus the `$this->helper($request)` chain below it.
 *
 * Deviation from the brief, which said to chase no helpers and let opacity cover
 * them: opacity doesn't cover them. PagesController::show() reads `$query`
 * directly (so it is NOT opaque) yet gets `lang` only from applyLanguage(), and
 * index() delegates two levels down to indexViaPages() before any param is read
 * — one level would have produced a wall of false failures on the largest tools.
 * A name matched in several files only widens the allowed set, never narrows it.
 *
 * @param array<string, true> $seen
 * @return array{query: list<string>, body: list<string>, required: list<string>, hasQuery: bool, hasBody: bool, queryHandedOff: bool, bodyHandedOff: bool}|null
 */
function walk(string $apiDir, string $method, array &$seen, ?string $preferFile = null, bool $own = true): ?array
{
    // ponytail: flat depth/visit cap instead of real call-graph analysis. If the
    // api plugin ever delegates deeper than this, the tail reads as "no source"
    // and the side is skipped as opaque — a miss, never a false failure.
    if (isset($seen[$method]) || count($seen) > 24) {
        return null;
    }
    $seen[$method] = true;

    $found = false;
    $out = ['query' => [], 'body' => [], 'required' => [], 'hasQuery' => false, 'hasBody' => false, 'queryHandedOff' => false, 'bodyHandedOff' => false];

    foreach (declaringFiles($apiDir, $method, $preferFile) as $file) {
        $src = methodSource($file, $method);
        if ($src === null) {
            continue;
        }
        $found = true;
        $reads = readSets($src, $file);
        $out['query'] = array_merge($out['query'], $reads['query']);
        $out['body'] = array_merge($out['body'], $reads['body']);
        $out['hasQuery'] = $out['hasQuery'] || $reads['hasQuery'];
        $out['hasBody'] = $out['hasBody'] || $reads['hasBody'];
        $out['queryHandedOff'] = $out['queryHandedOff'] || $reads['queryHandedOff'];
        $out['bodyHandedOff'] = $out['bodyHandedOff'] || $reads['bodyHandedOff'];
        // Only the routed method's own requireFields() is a requirement we can
        // trust; a helper's may sit behind a branch this request never takes.
        if ($own) {
            $out['required'] = array_merge($out['required'], $reads['required']);
        }

        foreach ($reads['helpers'] as $helper) {
            $sub = walk($apiDir, $helper, $seen, $file, false);
            if ($sub === null) {
                continue;
            }
            $out['query'] = array_merge($out['query'], $sub['query']);
            $out['body'] = array_merge($out['body'], $sub['body']);
            $out['hasQuery'] = $out['hasQuery'] || $sub['hasQuery'];
            $out['hasBody'] = $out['hasBody'] || $sub['hasBody'];
            $out['queryHandedOff'] = $out['queryHandedOff'] || $sub['queryHandedOff'];
            $out['bodyHandedOff'] = $out['bodyHandedOff'] || $sub['bodyHandedOff'];
        }
    }

    if (!$found) {
        return null;
    }
    $out['query'] = array_values(array_unique($out['query']));
    $out['body'] = array_values(array_unique($out['body']));
    $out['required'] = array_values(array_unique($out['required']));

    return $out;
}

// --- Tool side: synthesized arguments ---------------------------------------

function sampleValue(array $schema, string $name = ''): mixed
{
    if (isset($schema['enum'][0])) {
        return $schema['enum'][0];
    }

    return match ($schema['type'] ?? 'string') {
        'integer', 'number' => 1,
        'boolean' => true,
        'object' => sampleObject($schema),
        'array' => [sampleValue(is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'])],
        // The media upload schemas want something that survives base64_decode().
        default => $name === 'content_base64' ? base64_encode('x') : 'x',
    };
}

function sampleObject(array $schema): array
{
    $properties = $schema['properties'] ?? null;
    if (!is_array($properties) || $properties === []) {
        return ['k' => 'v'];
    }

    $out = [];
    foreach ($properties as $name => $sub) {
        $out[(string) $name] = sampleValue(is_array($sub) ? $sub : [], (string) $name);
    }

    return $out;
}

/**
 * Arg shapes per tool: full args (dodge most early returns), a required-only
 * minimum, and full-minus-one for each optional param — presence-branching
 * tools (e.g. list_media's route-vs-path split, get_users' list-vs-show)
 * dispatch on which optionals exist, so full args alone would leave whole
 * branches unexercised. Each shape then fans out per combination of enum
 * values. Shapes whose guards return an in-band error simply record no
 * request, which is fine — only issued requests are checked.
 *
 * @return list<array<string, mixed>>
 */
function argVariants(array $inputSchema): array
{
    $properties = (array) ($inputSchema['properties'] ?? []);
    $required = array_map('strval', (array) ($inputSchema['required'] ?? []));

    $base = [];
    $enums = [];
    foreach ($properties as $name => $schema) {
        $schema = is_array($schema) ? $schema : [];
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $enums[(string) $name] = $schema['enum'];
        } else {
            $base[(string) $name] = sampleValue($schema, (string) $name);
        }
    }

    $variants = [$base];
    $minimal = array_intersect_key($base, array_flip($required));
    if ($minimal !== $base) {
        $variants[] = $minimal;
    }
    foreach (array_keys(array_diff_key($base, array_flip($required))) as $optional) {
        $without = $base;
        unset($without[$optional]);
        if ($without !== $minimal) {
            $variants[] = $without;
        }
    }

    foreach ($enums as $name => $values) {
        $next = [];
        foreach ($variants as $variant) {
            foreach ($values as $value) {
                $next[] = $variant + [$name => $value];
            }
        }
        $variants = $next;
    }

    return $variants;
}

// --- Run -------------------------------------------------------------------

$routes = collectRoutes($apiDir . '/classes/Api');

$failed = 0;
$requests = 0;
$opaque = [];
$readCache = [];
$permChecked = [];

$fail = static function (string $message) use (&$failed): void {
    echo "  FAIL {$message}\n";
    $failed++;
};

// The FULL registry, built-ins included — site_info makes no requests, but
// whoami is api-backed and must stay contract-checked like any domain tool.
$tools = (new ReflectionMethod(Grav\Plugin\McpServer\ToolRegistry::class, 'all'))
    ->invoke(new Grav\Plugin\McpServer\ToolRegistry(null));

foreach ($tools as $name => $tool) {
    foreach (argVariants($tool['descriptor']['inputSchema'] ?? []) as $args) {
        $bridge = new RecordingBridge();
        try {
            ($tool['handler'])($bridge, $args);
        } catch (Throwable $e) {
            $fail(sprintf('%s threw %s: %s (args: %s)', $name, $e::class, $e->getMessage(), json_encode($args)));
            continue;
        }

        foreach ($bridge->calls as $call) {
            $requests++;
            $route = matchRoute($routes, $call['method'], $call['path']);
            if ($route === null) {
                $fail(sprintf('%s: no route for %s %s', $name, $call['method'], $call['path']));
                continue;
            }

            $where = $route['controller'] . '::' . $route['action'];

            // Permission contract: the tool's declared permission (visibility +
            // the [Requires: …] hint) must match what the route really enforces.
            if (!isset($permChecked[$name . '|' . $where])) {
                $permChecked[$name . '|' . $where] = true;
                $declared = $tool['permission'] ?? null;
                if (array_key_exists($name, $permissionPolicy)) {
                    if ($declared !== $permissionPolicy[$name]) {
                        $fail(sprintf("%s declares permission '%s' but \$permissionPolicy records '%s'", $name, $declared ?? 'null', $permissionPolicy[$name] ?? 'null'));
                    }
                } else {
                    $enforced = enforcedPermissions($apiDir . '/classes/Api/Controllers/' . $route['controller'] . '.php', $route['action']);
                    if (count($enforced) === 1 && $enforced[0] !== 'DYNAMIC') {
                        if ($declared !== $enforced[0]) {
                            $fail(sprintf("%s declares permission '%s' but %s enforces '%s'", $name, $declared ?? 'null', $where, $enforced[0]));
                        }
                    } else {
                        $fail(sprintf('%s: %s enforcement is %s — record a decision in $permissionPolicy', $name, $where, $enforced === [] ? 'not detectable' : implode(' + ', $enforced)));
                    }
                }
            }

            if (!array_key_exists($where, $readCache)) {
                $seen = [];
                $readCache[$where] = walk($apiDir, $route['action'], $seen, $apiDir . '/classes/Api/Controllers/' . $route['controller'] . '.php');
            }
            $reads = $readCache[$where];
            if ($reads === null) {
                $opaque[$where . ' (body unreadable)'] = true;
                continue;
            }

            $allowed = $allow[$name] ?? [];
            // No detectable source means extraction failed, not that nothing is read.
            $reads['queryOpaque'] = !$reads['hasQuery'] || $reads['queryHandedOff'];
            $reads['bodyOpaque'] = !$reads['hasBody'] || $reads['bodyHandedOff'];

            if ($reads['queryOpaque']) {
                if ($call['query'] !== []) {
                    $opaque[$where . ' (query)'] = true;
                }
            } else {
                foreach (array_diff($call['query'], $reads['query'], $allowed) as $key) {
                    $fail(sprintf("%s sends query '%s' that %s (%s %s) never reads", $name, $key, $where, $route['method'], $route['path']));
                }
            }

            if ($reads['bodyOpaque']) {
                if ($call['body'] !== null && $call['body'] !== []) {
                    $opaque[$where . ' (body)'] = true;
                }
                continue;
            }

            foreach (array_diff($call['body'] ?? [], $reads['body'], $allowed) as $key) {
                $fail(sprintf("%s sends body '%s' that %s (%s %s) never reads", $name, $key, $where, $route['method'], $route['path']));
            }
            foreach (array_diff($reads['required'], $call['body'] ?? []) as $key) {
                $fail(sprintf("%s never sends body '%s', which %s requires", $name, $key, $where));
            }
        }
    }
}

if ($opaque !== []) {
    echo '  skipped (opaque): ' . implode(', ', array_keys($opaque)) . "\n";
}

echo $failed === 0
    ? sprintf("param-map: OK (%d tools, %d requests checked, %d sides skipped as opaque)\n", count($tools), $requests, count($opaque))
    : sprintf("param-map: %d FAILED\n", $failed);
exit($failed === 0 ? 0 : 1);

}
