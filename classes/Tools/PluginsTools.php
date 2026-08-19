<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Plugins domain: runtime discovery of plugin-contributed admin UI surface
 * (sidebar/widgets/panels/custom pages) plus a generic menubar-action dispatcher.
 */
final class PluginsTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'discover_plugins' => [
                'permission' => 'api.access',
                'descriptor' => [
                    'name' => 'discover_plugins',
                    'title' => 'Discover Plugin Features',
                    'description' => 'Discover what features installed plugins expose: sidebar items, floating widgets, context panels, settings panels, and custom admin pages. [Requires: api.access]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                // No discovery cache here on purpose: a static in PHP-FPM outlives the
                // request and would leak one key's view of the site to the next.
                // Discovery is a few in-process GETs — cache on the ApiBridge instance
                // if it ever shows in a profile.
                'handler' => static function (ApiBridge $api, array $args): array {
                    $sidebarItems = self::listData($api, '/sidebar/items');

                    $slugs = [];
                    foreach ($sidebarItems as $item) {
                        $plugin = is_array($item) ? ($item['plugin'] ?? null) : null;
                        $route = is_array($item) ? ($item['route'] ?? null) : null;
                        if (is_string($plugin) && $plugin !== '' && is_string($route) && str_starts_with($route, '/plugin/') && !in_array($plugin, $slugs, true)) {
                            $slugs[] = $plugin;
                        }
                    }

                    $pluginPages = [];
                    foreach ($slugs as $slug) {
                        $page = self::pluginPage($api, $slug);
                        if ($page !== null) {
                            $pluginPages[] = $page;
                        }
                    }

                    $data = [
                        'sidebar_items' => $sidebarItems,
                        'floating_widgets' => self::listData($api, '/floating-widgets'),
                        'context_panels' => self::listData($api, '/context-panels'),
                        'settings_panels' => self::listData($api, '/settings/panels'),
                        'plugin_pages' => $pluginPages,
                    ];

                    return ApiBridge::toolJson($data);
                },
            ],

            'plugin_action' => [
                'permission' => 'api.access',
                'descriptor' => [
                    'name' => 'plugin_action',
                    'title' => 'Execute Plugin Action',
                    'description' => 'Execute a plugin-registered menubar action. Use discover_plugins first to see available actions for each plugin. [Requires: api.access]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'plugin' => ['type' => 'string', 'description' => 'Plugin slug'],
                            'action' => ['type' => 'string', 'description' => 'Action ID from the plugin page definition'],
                            'data' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Optional data payload for the action'],
                        ],
                        'required' => ['plugin', 'action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request(
                    'POST',
                    '/menubar/actions/' . rawurlencode((string) ($args['plugin'] ?? '')) . '/' . rawurlencode((string) ($args['action'] ?? '')),
                    [],
                    $args['data'] ?? null
                )),
            ],
        ];
    }

    /** GET $path; on error status or non-array data, fall back to [] (one failure must not fail the rest). */
    private static function listData(ApiBridge $api, string $path): array
    {
        $resp = $api->request('GET', $path);
        $data = $resp['status'] < 400 ? ($resp['json']['data'] ?? null) : null;

        return is_array($data) ? $data : [];
    }

    /** GET a plugin's custom admin page; absence (404 or otherwise) is tolerated, not an error. */
    private static function pluginPage(ApiBridge $api, string $slug): ?array
    {
        $resp = $api->request('GET', '/gpm/plugins/' . rawurlencode($slug) . '/page');
        if ($resp['status'] >= 400) {
            return null;
        }

        $data = $resp['json']['data'] ?? null;

        return is_array($data) ? $data : null;
    }
}
