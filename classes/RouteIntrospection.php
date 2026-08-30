<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

/**
 * Static analysis of grav-plugin-api controller source: what a routed action
 * enforces and what it reads off the query string and the JSON body.
 *
 * The api plugin is read as TEXT, never loaded — no Grav, no vendor, no
 * autoload — so this runs anywhere, including under bare PHP in tests.
 *
 * Two callers, one implementation: `tests/param-map.php` uses it to police the
 * curated tools' outgoing params against the controllers, and `list_api_routes`
 * uses it to describe live routes. Recovered detail therefore always follows
 * the api plugin version actually installed.
 */
final class RouteIntrospection
{
    /** `{name}` -> any segment, `{name:regex}` -> the declared regex. */
    public static function pathRegex(string $path): string
    {
        $regex = '';
        // ponytail: `[^}]+` for the inline regex means a placeholder whose own regex
        // contains `}` (e.g. `{n:\d{2,}}`) would mis-parse. None do; fix if one lands.
        foreach (preg_split('/(\{\w+(?::[^}]+)?\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
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
    public static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return []; // a controller outside the api plugin's own tree: nothing to scan
        }

        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = (string) $file;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<array{method: string, path: string, controller: string, action: string, static: bool, regex: string}> */
    public static function collectRoutes(string $dir): array
    {
        $routes = [];
        foreach (self::phpFiles($dir) as $file) {
            preg_match_all(
                '/addRoute\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*,\s*\[\s*(\w+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]/',
                self::fileSource($file),
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
                    'regex' => self::pathRegex($m[2]),
                ];
            }
        }

        return $routes;
    }

    /**
     * FastRoute's own precedence: the static map first, then variable routes in
     * declaration order.
     *
     * @param list<array{method: string, path: string, controller: string, action: string, static: bool, regex: string}> $routes
     * @return array{method: string, path: string, controller: string, action: string, static: bool, regex: string}|null
     */
    public static function matchRoute(array $routes, string $method, string $path): ?array
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

    public static function fileSource(string $file): string
    {
        static $cache = [];

        return $cache[$file] ??= (string) @file_get_contents($file);
    }

    /**
     * Source text of one method, brace-counted over PHP tokens.
     *
     * Token-level counting (not char-level) is what makes this safe —
     * braces inside strings and comments arrive as one token and never count.
     * T_CURLY_OPEN/T_DOLLAR_OPEN_CURLY_BRACES are interpolation opens whose `}` is
     * a bare token, so they must count as opens to stay balanced.
     */
    public static function methodSource(string $file, string $method): ?string
    {
        static $cache = [];
        if (array_key_exists($file . '::' . $method, $cache)) {
            return $cache[$file . '::' . $method];
        }
        $cache[$file . '::' . $method] = null;

        $tokens = token_get_all(self::fileSource($file));
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
    public static function enclosingCall(string $src, int $pos): string
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
     *
     * @param list<string> $vars
     */
    public static function isOpaque(string $src, array $vars): bool
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
                if (!in_array(self::enclosingCall($src, $pos), $safe, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $vars
     * @return list<string> the quoted keys read off any of $vars, plus $literal['k'] reads
     */
    public static function keysRead(string $src, array $vars): array
    {
        $keys = [];
        foreach ($vars as $var) {
            preg_match_all('/\$' . preg_quote($var, '/') . '\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $src, $m);
            $keys = array_merge($keys, $m[1]);
        }

        return $keys;
    }

    /** Value of a scalar `const NAME = 'value';` in $file, null if absent. */
    public static function constScalar(string $file, string $name): ?string
    {
        return preg_match('/const\s+(?:[\w|?\\\\]+\s+)?' . preg_quote($name, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]/', self::fileSource($file), $m) === 1 ? $m[1] : null;
    }

    /**
     * Distinct permission strings the routed action's own body enforces.
     * Resolves requirePermission() literals and self::CONSTs, requireSuper()
     * (= the 'admin.super' scope cap), and the trailing permission argument of
     * authorizePageAction(). Anything else becomes 'DYNAMIC', which forces an
     * explicit decision by the caller instead of a silent pass.
     *
     * @return list<string>
     */
    public static function enforcedPermissions(string $file, string $action): array
    {
        $src = self::methodSource($file, $action);
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
                $out[] = self::constScalar($file, $const[1]) ?? 'DYNAMIC';
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
                $out[] = ($last[2] ?? '') !== '' ? $last[2] : (self::constScalar($file, $last[1]) ?? 'DYNAMIC');
            } else {
                $out[] = 'DYNAMIC';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Values of a `const NAME = ['a', 'b'];` array literal in $file.
     *
     * @return list<string>
     */
    public static function constArray(string $file, string $name): array
    {
        if (preg_match('/const\s+(?:[\w|?\\\\]+\s+)?' . preg_quote($name, '/') . '\s*=\s*\[([^\]]*)\]/', self::fileSource($file), $m) !== 1) {
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
    public static function readSets(string $src, string $file): array
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

        $query = self::keysRead($src, $queryVars);
        $body = self::keysRead($src, $bodyVars);

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
            $query = array_merge($query, self::constArray($file, $const));
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
            'queryHandedOff' => self::isOpaque($src, $queryVars),
            'bodyHandedOff' => self::isOpaque($src, $bodyVars),
            'helpers' => $helpers,
        ];
    }

    /**
     * Files under classes/Api/ that declare `function $method(`, $preferFile
     * winning outright.
     *
     * @return list<string>
     */
    public static function declaringFiles(string $apiDir, string $method, ?string $preferFile): array
    {
        $needle = 'function ' . $method . '(';
        if ($preferFile !== null && str_contains(self::fileSource($preferFile), $needle)) {
            return [$preferFile];
        }

        return array_values(array_filter(
            self::phpFiles($apiDir . '/classes/Api'),
            static fn(string $file): bool => str_contains(self::fileSource($file), $needle)
        ));
    }

    /**
     * Merged read picture for a method plus the `$this->helper($request)` chain below it.
     *
     * Helpers are chased rather than left to opacity: opacity doesn't cover them.
     * PagesController::show() reads `$query` directly (so it is NOT opaque) yet gets
     * `lang` only from applyLanguage(), and index() delegates two levels down to
     * indexViaPages() before any param is read — one level would have produced a wall
     * of false failures on the largest tools. A name matched in several files only
     * widens the allowed set, never narrows it.
     *
     * @param array<string, true> $seen
     * @return array{query: list<string>, body: list<string>, required: list<string>, hasQuery: bool, hasBody: bool, queryHandedOff: bool, bodyHandedOff: bool}|null
     */
    public static function walk(string $apiDir, string $method, array &$seen, ?string $preferFile = null, bool $own = true): ?array
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

        foreach (self::declaringFiles($apiDir, $method, $preferFile) as $file) {
            $src = self::methodSource($file, $method);
            if ($src === null) {
                continue;
            }
            $found = true;
            $reads = self::readSets($src, $file);
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
                $sub = self::walk($apiDir, $helper, $seen, $file, false);
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
}
