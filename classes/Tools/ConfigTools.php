<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Config domain: tools over grav-plugin-api's /config endpoints.
 */
final class ConfigTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'list_config_scopes' => [
                'permission' => 'api.config.read',
                'descriptor' => [
                    'name' => 'list_config_scopes',
                    'title' => 'List Config Scopes',
                    'description' => 'List the enumerable configuration scopes: core scopes (system, site, security, ...) plus any custom configs, filtered to what your permissions allow. Plugin and theme configs are NOT listed here, but ARE readable and writable directly — use get_config/update_config with scope "plugins/{name}" or "themes/{name}". [Requires: api.config.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/config')),
            ],

            'get_config' => [
                'permission' => 'api.config.read',
                'descriptor' => [
                    'name' => 'get_config',
                    'title' => 'Get Config',
                    'description' => 'Read one config scope, with an ETag for update_config. `shadowed`, when present, lists the keys an environment folder or a GRAV_CONFIG__* server variable sets over this layer, with the value the site actually uses: saving those keys here changes nothing. [Requires: api.config.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => ['type' => 'string', 'description' => 'e.g. "system", "site", "plugins/email", "themes/quark"'],
                        ],
                        'required' => ['scope'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse(
                    $api->request('GET', '/config/' . ApiBridge::path($args, 'scope')),
                    true,
                    self::wrapConfig(...)
                ),
            ],

            'update_config' => [
                'permission' => 'api.config.write',
                'descriptor' => [
                    'name' => 'update_config',
                    'title' => 'Update Config',
                    'description' => 'Update a config scope. Writes are differential — only keys that differ from parent defaults persist. Pass `etag` from `get_config` for conflict detection. The system, security, scheduler and backups scopes and the api, admin2, login, email and git-sync plugins (plus login-* and email-* providers) need a super-admin. [Requires: api.config.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => ['type' => 'string', 'description' => 'e.g. "site", "plugins/email"'],
                            'values' => ['type' => 'object', 'additionalProperties' => true],
                            'etag' => ['type' => 'string'],
                            'environment' => ['type' => 'string', 'description' => 'Write to existing user/env/<name> (create with manage_environments first), e.g. "production"'],
                        ],
                        'required' => ['scope', 'values'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $headers = [];
                    if (isset($args['etag'])) {
                        $headers['If-Match'] = (string) $args['etag'];
                    }
                    if (isset($args['environment'])) {
                        $headers['X-Config-Environment'] = (string) $args['environment'];
                    }

                    return ApiBridge::fromResponse(
                        $api->request('PATCH', '/config/' . ApiBridge::path($args, 'scope'), [], $args['values'] ?? [], $headers),
                        true,
                        self::wrapConfig(...)
                    );
                },
            ],

            'revert_config' => [
                'permission' => 'api.config.write',
                'descriptor' => [
                    'name' => 'revert_config',
                    'title' => 'Revert Config',
                    'description' => 'Revert configuration to inherited values. `keys` drops those overrides from the scope\'s file in the targeted layer (environment or base user/config); `reset` deletes the whole override file so the scope falls back to the parent layer and shipped defaults. The system, security, scheduler and backups scopes and the api, admin2, login, email and git-sync plugins (plus login-* and email-* providers) need a super-admin. [Requires: api.config.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => ['type' => 'string', 'description' => 'e.g. "site", "plugins/email"'],
                            'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dotted config paths'],
                            'reset' => ['type' => 'boolean'],
                            'etag' => ['type' => 'string', 'description' => 'From get_config, for conflict detection'],
                            'environment' => ['type' => 'string', 'description' => 'Existing user/env/<name> (create with manage_environments first), e.g. "production"'],
                        ],
                        'required' => ['scope'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $keys = $args['keys'] ?? null;
                    $reset = $args['reset'] ?? false;
                    if ((!is_array($keys) || $keys === []) && $reset !== true) {
                        return ApiBridge::toolJson(['error' => 'Provide keys to revert or reset: true']);
                    }

                    $body = [];
                    if (isset($args['keys'])) {
                        $body['keys'] = $args['keys'];
                    }
                    if (isset($args['reset'])) {
                        $body['reset'] = $args['reset'];
                    }

                    $headers = [];
                    if (isset($args['etag'])) {
                        $headers['If-Match'] = (string) $args['etag'];
                    }
                    if (isset($args['environment'])) {
                        $headers['X-Config-Environment'] = (string) $args['environment'];
                    }

                    return ApiBridge::fromResponse(
                        $api->request('POST', '/config/' . ApiBridge::path($args, 'scope') . '/revert', [], $body, $headers),
                        true,
                        self::wrapConfig(...)
                    );
                },
            ],
        ];
    }

    /**
     * ApiBridge::fromResponse() (withEtag: true) merges `_etag` into $data itself
     * before calling this transform; pull it back out to a sibling key so the
     * shape is {config: <raw config object>, _etag?: <ETag>, shadowed?: [...]}.
     * `shadowed` is the api's meta.shadowed (1.0.50), which fromResponse drops.
     */
    private static function wrapConfig(mixed $data, mixed $json = null): array
    {
        $etag = is_array($data) ? ($data['_etag'] ?? null) : null;
        if ($etag !== null) {
            unset($data['_etag']);
        }

        $result = ['config' => $data];
        if ($etag !== null) {
            $result['_etag'] = $etag;
        }
        if (!empty($json['meta']['shadowed'])) {
            $result['shadowed'] = $json['meta']['shadowed'];
        }

        return $result;
    }
}
