<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * GPM domain: package-manager tools over grav-plugin-api's /gpm endpoints.
 */
final class GpmTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'get_packages' => [
                'permission' => 'api.gpm.read',
                'descriptor' => [
                    'name' => 'get_packages',
                    'title' => 'Get Packages',
                    'description' => 'Inspect installed packages: list the installed plugins or themes, read one package\'s details, or check what updates are available. [Requires: api.gpm.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['installed', 'info', 'updates'], 'description' => 'What to return (default: installed). "installed" lists installed packages with version info and update availability; "info" details one package; "updates" checks Grav core, plugins, and themes for available updates.'],
                            'type' => ['type' => 'string', 'enum' => ['plugins', 'themes'], 'description' => 'Package type. Required for the "installed" and "info" views; ignored by "updates".'],
                            'slug' => ['type' => 'string', 'description' => 'Package slug (e.g. "email", "quark"). Required for the "info" view; ignored by the others.'],
                            'include' => ['type' => 'string', 'enum' => ['readme', 'changelog'], 'description' => 'Also fetch readme or changelog content. "info" view only.'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $type = (string) ($args['type'] ?? '');
                    $slug = (string) ($args['slug'] ?? '');

                    return match ($args['view'] ?? 'installed') {
                        'installed' => $type === ''
                            ? ApiBridge::toolJson(['error' => 'type is required for the installed view'])
                            : ApiBridge::fromResponse($api->request('GET', '/gpm/' . $type)),
                        'info' => $type === '' || $slug === ''
                            ? ApiBridge::toolJson(['error' => 'type and slug are required for the info view'])
                            : self::info($api, $args),
                        'updates' => ApiBridge::fromResponse($api->request('GET', '/gpm/updates')),
                        default => ApiBridge::toolError('Invalid view. Must be one of: installed, info, updates'),
                    };
                },
            ],

            'search_packages' => [
                'permission' => 'api.gpm.read',
                'descriptor' => [
                    'name' => 'search_packages',
                    'title' => 'Search Packages',
                    'description' => 'Search the GPM repository for available plugins and themes to install. [Requires: api.gpm.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Search query'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Results per page'],
                        ],
                        'required' => ['query'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/gpm/search', [
                    // The MCP-facing name is 'query'; the api endpoint reads 'q'.
                    'q' => $args['query'] ?? null,
                    'page' => $args['page'] ?? null,
                    'per_page' => $args['per_page'] ?? null,
                ])),
            ],

            'manage_packages' => [
                'permission' => 'api.gpm.write',
                'descriptor' => [
                    'name' => 'manage_packages',
                    'title' => 'Manage Packages',
                    'description' => 'Install, remove, or update packages. "update" updates one named package; "update_all" updates every installed plugin and theme; "upgrade_grav" self-upgrades the Grav core itself. [Requires: api.gpm.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['install', 'remove', 'update', 'update_all', 'upgrade_grav'], 'description' => 'What to do. "install" adds a package from the GPM repository (configure it afterwards via update_config with scope "plugins/{slug}" or "themes/{slug}"); "remove" uninstalls one, deleting its files; "update" updates the single named package (auto-detected from the slug) plus its required dependencies; "update_all" bulk-updates every updatable plugin and theme, reporting failures per package rather than throwing; "upgrade_grav" upgrades the Grav core itself and refuses symlink installs (check `is_symlink` via get_packages with view "updates" first, and expect to need super_admin).'],
                            'package' => ['type' => 'string', 'description' => 'Package slug. Required for install, remove, and update; meaningless for update_all and upgrade_grav, which take no target.'],
                            'type' => ['type' => 'string', 'enum' => ['plugin', 'theme'], 'description' => 'Package type. Required for install; meaningless for every other action.'],
                            'license' => ['type' => 'string', 'description' => 'License key (for premium packages). Install only.'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $package = (string) ($args['package'] ?? '');

                    return match ($args['action'] ?? null) {
                        'install' => $package === '' || ($args['type'] ?? '') === ''
                            ? ApiBridge::toolJson(['error' => 'package and type are required for the install action'])
                            : ApiBridge::fromResponse($api->request(
                                'POST',
                                '/gpm/install',
                                [],
                                ApiBridge::pick($args, ['package', 'type', 'license'])
                            )),
                        'remove' => $package === ''
                            ? ApiBridge::toolJson(['error' => 'package is required for the remove action'])
                            : ApiBridge::fromResponse($api->request(
                                'POST',
                                '/gpm/remove',
                                [],
                                ApiBridge::pick($args, ['package'])
                            )),
                        'update' => $package === ''
                            ? ApiBridge::toolJson(['error' => 'package is required for the update action'])
                            : ApiBridge::fromResponse($api->request(
                                'POST',
                                '/gpm/update',
                                [],
                                ApiBridge::pick($args, ['package'])
                            )),
                        'update_all' => ApiBridge::fromResponse($api->request('POST', '/gpm/update-all')),
                        'upgrade_grav' => ApiBridge::fromResponse($api->request('POST', '/gpm/upgrade')),
                        default => ApiBridge::toolError('Invalid action. Must be one of: install, remove, update, update_all, upgrade_grav'),
                    };
                },
            ],
        ];
    }

    /** The "info" view: package details, optionally with readme or changelog folded in. */
    private static function info(ApiBridge $api, array $args): array
    {
        $base = '/gpm/' . ($args['type'] ?? '') . '/' . rawurlencode((string) ($args['slug'] ?? ''));
        $resp = $api->request('GET', $base);
        if ($resp['status'] >= 400) {
            return ApiBridge::fromResponse($resp);
        }

        $include = $args['include'] ?? null;
        if ($include !== 'readme' && $include !== 'changelog') {
            return ApiBridge::fromResponse($resp);
        }

        $extra = $api->request('GET', $base . '/' . $include);
        if ($extra['status'] >= 400) {
            return ApiBridge::fromResponse($extra);
        }

        return ApiBridge::fromResponse($resp, false, static function (array $data) use ($extra, $include): array {
            $data[$include] = $extra['json']['data'] ?? null;

            return $data;
        });
    }
}
