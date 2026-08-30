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
                    'description' => 'Escape hatch for grav-plugin-api REST routes that have no curated tool. Paths are routes relative to the API root (e.g. "/pages", "/audit/export"), not full URLs. Returns {status, content_type, body} (plus etag when the response carries one); errors come back as the upstream RFC 7807 problem document verbatim. Prefer a curated tool when one exists. [Requires: api.mcp-server.raw]',
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
        ];
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

        return self::envelope($resp);
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
     */
    public static function envelope(array $resp): array
    {
        $type = strtolower(trim(explode(';', $resp['headers']['content-type'] ?? '')[0]));
        $raw = (string) ($resp['body'] ?? '');

        $result = ['status' => $resp['status'], 'content_type' => $type];
        $etag = trim($resp['headers']['etag'] ?? '', '"');
        if ($etag !== '') {
            $result['etag'] = $etag;
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
