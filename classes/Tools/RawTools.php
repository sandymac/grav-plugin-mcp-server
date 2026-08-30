<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Raw passthrough: one tool that dispatches an arbitrary request through the
 * api plugin's own router, covering routes no curated tool wraps.
 *
 * The gating permission is unlock-only — it decides whether the tool is visible
 * and callable, nothing more. Every dispatched request still carries the
 * caller's own API key, so the api plugin's per-route requirePermission() is
 * the only authorization that matters. There is deliberately no MCP-side route
 * filter (see DECISIONS.md).
 */
final class RawTools
{
    /** Text bodies above this many bytes come back truncated; binary never comes back at all. */
    private const int TEXT_CAP = 131072;

    /**
     * Caller headers that would forge identity, confuse the router, or fight
     * the body we build. Dropped silently, and X-API-Key is stamped by
     * ApiBridge::request() *after* these, so an override is impossible anyway.
     */
    private const array DENIED_HEADERS = [
        'x-api-key', 'authorization', 'cookie', 'host',
        'content-length', 'content-type', 'transfer-encoding',
    ];

    private const array METHODS = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];

    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'api_request' => [
                'permission' => 'api.mcp-server.raw',
                'descriptor' => [
                    'name' => 'api_request',
                    'title' => 'Raw API Request',
                    'description' => 'Escape hatch for grav-plugin-api REST routes that have no curated tool. Paths are routes relative to the API root (e.g. "/pages", "/audit/export"), not full URLs. Returns {status, content_type, body} (plus etag when the response carries one); errors come back as the upstream RFC 7807 problem document verbatim, and a 404 that matched no route also carries `suggestions`: the nearest routes in the live table. Prefer a curated tool when one exists. [Requires: api.mcp-server.raw]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'method' => ['type' => 'string', 'enum' => self::METHODS, 'description' => 'HTTP method'],
                            'path' => ['type' => 'string', 'description' => 'Route path relative to the API root, e.g. /pages'],
                            'query' => ['type' => 'object', 'description' => 'Query string parameters (scalar values)'],
                            'body' => ['type' => 'object', 'description' => 'JSON request body'],
                            'headers' => ['type' => 'object', 'description' => 'Extra request headers, e.g. If-Match. Auth, cookie, host and content headers are ignored.'],
                        ],
                        'required' => ['method', 'path'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => self::dispatch($api, $args),
            ],
            'list_api_routes' => [
                'permission' => 'api.mcp-server.raw',
                'descriptor' => [
                    'name' => 'list_api_routes',
                    'title' => 'List API Routes',
                    'description' => 'Lists the live grav-plugin-api REST route table — every route api_request can call, core routes plus whatever other plugins registered on this site. Filter with search (case-insensitive substring of "METHOD /path"), method, and prefix; limit defaults to 50. Returns {api_root, routes, total_matched}; each row is {method, path} and may carry detail recovered from the controller source (permission, query, body). [Requires: api.mcp-server.raw]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => ['type' => 'string', 'description' => 'Case-insensitive substring match against "METHOD /path"'],
                            'method' => ['type' => 'string', 'enum' => self::METHODS, 'description' => 'Only routes with this HTTP method'],
                            'prefix' => ['type' => 'string', 'description' => 'Only routes whose path starts with this prefix, e.g. /pages'],
                            'limit' => ['type' => 'integer', 'description' => 'Maximum rows returned (default 50, max 200)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => self::listRoutes($api, $args),
            ],
        ];
    }

    /** The live route table, filtered and paged. Filters are conjunctive. */
    private static function listRoutes(ApiBridge $api, array $args): array
    {
        $search = strtolower(trim((string) ($args['search'] ?? '')));
        $method = strtoupper(trim((string) ($args['method'] ?? '')));
        $prefix = (string) ($args['prefix'] ?? '');
        $prefix = $prefix === '' ? '' : '/' . ltrim($prefix, '/');
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

        $matched = [];
        foreach ($api->routes() as $route) {
            if ($method !== '' && $route['method'] !== $method) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($route['path'], $prefix)) {
                continue;
            }
            if ($search !== '' && !str_contains(strtolower($route['method'] . ' ' . $route['path']), $search)) {
                continue;
            }
            $matched[] = $route;
        }

        usort($matched, static fn(array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return ApiBridge::toolJson([
            'api_root' => $api->apiRoot(),
            'routes' => array_map(
                static fn(array $route): array => ['method' => $route['method'], 'path' => $route['path']],
                array_slice($matched, 0, $limit)
            ),
            'total_matched' => count($matched),
            'hint' => 'Every row is callable with api_request: pass its method and path (paths are relative to api_root).',
        ]);
    }

    private static function dispatch(ApiBridge $api, array $args): array
    {
        $method = strtoupper((string) ($args['method'] ?? ''));
        if (!in_array($method, self::METHODS, true)) {
            return ApiBridge::toolError('Invalid method. Must be one of: ' . implode(', ', self::METHODS));
        }

        $path = '/' . ltrim((string) ($args['path'] ?? ''), '/');
        // Cheap containment guard: request() pastes $path onto the api base, so
        // a traversal segment must never reach it (FastRoute would 404 anyway).
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return ApiBridge::toolError('Invalid path: ".." segments are not allowed.');
        }

        $resp = $api->request(
            $method,
            $path,
            (array) ($args['query'] ?? []),
            isset($args['body']) && is_array($args['body']) ? $args['body'] : null,
            self::headers($args['headers'] ?? [])
        );

        // Enumerating the route table costs a router build, so only a genuine
        // routing miss pays for it — not a 404 from a controller.
        $missed = $resp['status'] === 404
            && is_array($resp['json'])
            && str_contains((string) ($resp['json']['detail'] ?? ''), 'No route matches');

        return self::envelope($resp, $missed ? self::suggest($api, $method, $path) : []);
    }

    /**
     * Nearest live routes to a path that matched nothing: most shared path
     * segments first, same-method ahead of a method mismatch at equal overlap.
     *
     * @return list<string> "METHOD /path"
     */
    private static function suggest(ApiBridge $api, string $method, string $path): array
    {
        try {
            $routes = $api->routes();
        } catch (\Throwable) {
            return []; // a hint is never worth failing the call over
        }

        $wanted = self::segments($path);
        $scored = [];
        foreach ($routes as $route) {
            $shared = count(array_intersect($wanted, self::segments($route['path'])));
            if ($shared > 0) {
                $scored[] = [
                    'shared' => $shared,
                    'same' => (int) ($route['method'] === $method),
                    'label' => $route['method'] . ' ' . $route['path'],
                ];
            }
        }
        // Stable sort (PHP 8), so equal scores keep declaration order.
        usort($scored, static fn(array $a, array $b): int => [$b['shared'], $b['same']] <=> [$a['shared'], $a['same']]);

        return array_slice(array_column($scored, 'label'), 0, 5);
    }

    /** @return list<string> non-empty path segments, placeholders included verbatim */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
    }

    /** @return array<string, string> caller headers minus the denylist (case-insensitive). */
    public static function headers(mixed $headers): array
    {
        $out = [];
        foreach ((array) $headers as $name => $value) {
            if (!in_array(strtolower((string) $name), self::DENIED_HEADERS, true)) {
                $out[(string) $name] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Response → {status, content_type, body} with no reshaping: JSON verbatim
     * (problem documents included), text capped, binary refused.
     *
     * @param array{status:int, headers:array<string,string>, json:mixed, body?:string} $resp
     * @param list<string> $suggestions nearest routes, on a routing miss only
     */
    public static function envelope(array $resp, array $suggestions = []): array
    {
        $type = strtolower(trim(explode(';', $resp['headers']['content-type'] ?? '')[0]));
        $raw = (string) ($resp['body'] ?? '');

        $result = ['status' => $resp['status'], 'content_type' => $type];
        $etag = trim($resp['headers']['etag'] ?? '', '"');
        if ($etag !== '') {
            $result['etag'] = $etag;
        }
        if ($suggestions !== []) {
            $result['suggestions'] = $suggestions;
        }

        $isText = str_starts_with($type, 'text/')
            || in_array($type, ['application/javascript', 'application/xml', 'application/csv'], true);

        if ($raw === '') {
            $result['body'] = null;
        } elseif ($type === 'application/json' || str_ends_with($type, '+json')) {
            $result['body'] = $resp['json'];
        } elseif ($isText) {
            $size = strlen($raw);
            if ($size > self::TEXT_CAP) {
                // mb_strcut, not substr: cutting mid-sequence would make the
                // whole result unencodable as JSON.
                $result['body'] = mb_strcut($raw, 0, self::TEXT_CAP, 'UTF-8');
                $result['truncated'] = true;
                $result['size'] = $size;
            } else {
                $result['body'] = $raw;
            }
        } else {
            $result['size'] = strlen($raw);
            $result['error'] = 'binary response not transported over MCP';

            return ApiBridge::toolJson($result, true);
        }

        return ApiBridge::toolJson($result, $resp['status'] >= 400);
    }
}
