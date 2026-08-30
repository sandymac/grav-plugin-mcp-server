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

use Grav\Plugin\McpServer\RouteIntrospection;

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
require __DIR__ . '/../classes/RouteIntrospection.php'; // the shared controller-source analyzer
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

$routes = RouteIntrospection::collectRoutes($apiDir . '/classes/Api');

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
    // The raw-passthrough pair issues no static request to cross-reference:
    // api_request takes its (method, path) tuple from the caller at runtime,
    // and list_api_routes reads the route table instead of dispatching.
    // Contract-checked in smoke.php instead.
    if ($name === 'api_request' || $name === 'list_api_routes') {
        continue;
    }

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
            $route = RouteIntrospection::matchRoute($routes, $call['method'], $call['path']);
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
                    $enforced = RouteIntrospection::enforcedPermissions($apiDir . '/classes/Api/Controllers/' . $route['controller'] . '.php', $route['action']);
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
                $readCache[$where] = RouteIntrospection::walk($apiDir, $route['action'], $seen, $apiDir . '/classes/Api/Controllers/' . $route['controller'] . '.php');
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
