<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * System + Dashboard domain: tools over grav-plugin-api's /system, /cache,
 * /scheduler, /reports, /auth/password-policy, and /dashboard endpoints.
 */
final class SystemTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'get_system_info' => [
                'permission' => 'api.system.read',
                'descriptor' => [
                    'name' => 'get_system_info',
                    'title' => 'Get System Info',
                    'description' => 'Get comprehensive system information including Grav version, PHP version, disk usage, environment, and installed package counts. [Requires: api.system.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/system/info')),
            ],

            'clear_cache' => [
                'permission' => 'api.system.write',
                'descriptor' => [
                    'name' => 'clear_cache',
                    'title' => 'Clear Cache',
                    'description' => 'Clear the Grav cache — everything ("all"), compiled pages/twig ("standard", default), image cache, CSS/JS pipeline, or tmp files. [Requires: api.system.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'scope' => ['type' => 'string', 'enum' => ['all', 'standard', 'images', 'assets', 'tmp'], 'description' => 'Cache scope to clear (default: "standard")'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $scope = $args['scope'] ?? null;

                    return ApiBridge::fromResponse(
                        $api->request('DELETE', '/cache', ['scope' => $scope]),
                        successMessage: sprintf('Cache cleared (scope: %s).', $scope ?: 'standard')
                    );
                },
            ],

            'get_logs' => [
                'permission' => 'api.system.read',
                'descriptor' => [
                    'name' => 'get_logs',
                    'title' => 'Get Logs',
                    'description' => 'View Grav system logs with optional filtering by level (ERROR, WARNING, INFO, DEBUG) and text search. [Requires: api.system.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'level' => ['type' => 'string', 'enum' => ['ERROR', 'WARNING', 'INFO', 'DEBUG'], 'description' => 'Filter by log level'],
                            'search' => ['type' => 'string', 'description' => 'Search in log messages'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/system/logs', [
                    'level' => $args['level'] ?? null,
                    'search' => $args['search'] ?? null,
                    'page' => $args['page'] ?? 1,
                    'per_page' => $args['per_page'] ?? 50,
                ])),
            ],

            'create_backup' => [
                // The api plugin gates backups behind their own narrow permission
                // (credential-bearing archives, GHSA-2f86-9cp8-6hcf).
                'permission' => 'api.system.backup',
                'descriptor' => [
                    'name' => 'create_backup',
                    'title' => 'Create Backup',
                    'description' => 'Create a full backup of the Grav installation. Returns the backup filename, size, and date. [Requires: api.system.backup]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('POST', '/system/backup')),
            ],

            'list_backups' => [
                'permission' => 'api.system.backup',
                'descriptor' => [
                    'name' => 'list_backups',
                    'title' => 'List Backups',
                    'description' => 'List all available backups with filenames, sizes, and dates. [Requires: api.system.backup]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/system/backups')),
            ],

            'get_scheduler' => [
                'permission' => 'api.scheduler.read',
                'descriptor' => [
                    'name' => 'get_scheduler',
                    'title' => 'Get Scheduler',
                    'description' => 'View scheduler information: configured jobs with their status, crontab installation status, and execution history. [Requires: api.scheduler.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['jobs', 'status', 'history'], 'description' => 'Which view to return (default: all)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $path = match ($args['view'] ?? null) {
                        'status' => '/scheduler/status',
                        'history' => '/scheduler/history',
                        default => '/scheduler/jobs',
                    };

                    return ApiBridge::fromResponse($api->request('GET', $path));
                },
            ],

            'run_scheduler' => [
                'permission' => 'api.scheduler.write',
                'descriptor' => [
                    'name' => 'run_scheduler',
                    'title' => 'Run Scheduler',
                    'description' => 'Manually trigger a scheduler run to execute due jobs immediately. [Requires: api.scheduler.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('POST', '/scheduler/run')),
            ],

            'run_reports' => [
                'permission' => 'api.reports.read',
                'descriptor' => [
                    'name' => 'run_reports',
                    'title' => 'Run Reports',
                    'description' => 'Generate diagnostic reports including security checks, YAML linting, and any plugin-contributed reports. Each report contains a status and list of items. [Requires: api.reports.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/reports')),
            ],

            'list_environments' => [
                'permission' => 'api.system.read',
                'descriptor' => [
                    'name' => 'list_environments',
                    'title' => 'List Environments',
                    'description' => 'List configurable Grav environments under user/env/, plus the auto-detected current one. Use with `update_config`\'s `environment` arg. [Requires: api.system.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/system/environments')),
            ],

            'create_environment' => [
                'permission' => 'api.config.write',
                'descriptor' => [
                    'name' => 'create_environment',
                    'title' => 'Create Environment',
                    'description' => 'Create a new `user/env/<name>/config/` folder for environment-scoped configuration overrides. Environments are not created implicitly — clients must opt in. [Requires: api.config.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Environment name (folder under user/env/, e.g. "production", "staging")'],
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request(
                    'POST',
                    '/system/environments',
                    [],
                    ApiBridge::pick($args, ['name'])
                )),
            ],

            'get_password_policy' => [
                'permission' => null,
                'descriptor' => [
                    'name' => 'get_password_policy',
                    'title' => 'Get Password Policy',
                    'description' => 'Get the configured password policy (regex, minimum length, rules). Public — no authentication required.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/auth/password-policy')),
            ],

            'get_dashboard' => [
                'permission' => 'api.system.read',
                'descriptor' => [
                    'name' => 'get_dashboard',
                    'title' => 'Get Dashboard',
                    'description' => 'Get dashboard info: site overview statistics (page counts, user counts, plugin/theme counts, media stats, last backup info) or system notifications from getgrav.org. [Requires: api.system.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['stats', 'notifications'], 'description' => 'Which view to return (default: stats)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $path = match ($args['view'] ?? null) {
                        'notifications' => '/dashboard/notifications',
                        default => '/dashboard/stats',
                    };

                    return ApiBridge::fromResponse($api->request('GET', $path));
                },
            ],

            'dismiss_notification' => [
                'permission' => 'api.system.write',
                'descriptor' => [
                    'name' => 'dismiss_notification',
                    'title' => 'Dismiss Notification',
                    'description' => 'Dismiss/hide a system notification so it no longer appears. [Requires: api.system.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Notification ID to dismiss'],
                        ],
                        'required' => ['id'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $id = (string) ($args['id'] ?? '');

                    return ApiBridge::fromResponse(
                        $api->request('POST', '/dashboard/notifications/' . rawurlencode($id) . '/hide'),
                        successMessage: sprintf('Notification "%s" dismissed.', $id)
                    );
                },
            ],

            'get_dashboard_widgets' => [
                'permission' => 'api.access',
                'descriptor' => [
                    'name' => 'get_dashboard_widgets',
                    'title' => 'Get Dashboard Widgets',
                    'description' => 'Get the resolved dashboard widget list (visibility, size, order) after site and per-user overrides. [Requires: api.access]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/dashboard/widgets')),
            ],

            'update_dashboard_layout' => [
                'permission' => 'api.access',
                'descriptor' => [
                    'name' => 'update_dashboard_layout',
                    'title' => 'Update Dashboard Layout',
                    'description' => 'Save the current user\'s dashboard layout (visibility, size, order). Site-hidden widgets can\'t be re-enabled per-user; invalid sizes coerce to the widget default. [Requires: api.access]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'widgets' => [
                                'type' => 'array',
                                'items' => self::widgetLayoutItemSchema(),
                                'description' => 'Per-widget layout overrides',
                            ],
                        ],
                        'required' => ['widgets'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request(
                    'PATCH',
                    '/dashboard/layout',
                    [],
                    ['widgets' => $args['widgets'] ?? []]
                )),
            ],

            'update_site_dashboard_layout' => [
                // requireSuper() in the api controller = the 'admin.super' scope cap,
                // so scoped keys without that scope no longer see a tool they can't call.
                'permission' => 'admin.super',
                'descriptor' => [
                    'name' => 'update_site_dashboard_layout',
                    'title' => 'Update Site Dashboard Layout',
                    'description' => 'Save the site-wide default dashboard layout. Hides widgets globally for everyone (super-admin only). [Requires: admin.super]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'widgets' => [
                                'type' => 'array',
                                'items' => self::widgetLayoutItemSchema(),
                                'description' => 'Per-widget site-default overrides',
                            ],
                        ],
                        'required' => ['widgets'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request(
                    'PATCH',
                    '/dashboard/site-layout',
                    [],
                    ['widgets' => $args['widgets'] ?? []]
                )),
            ],
        ];
    }

    /** Shared {id, visible?, size?, order?} item schema for the two dashboard-layout tools. */
    private static function widgetLayoutItemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Widget ID'],
                'visible' => ['type' => 'boolean', 'description' => 'Whether the widget is shown'],
                'size' => ['type' => 'string', 'enum' => ['xs', 'sm', 'md', 'lg', 'xl'], 'description' => 'Widget size'],
                'order' => ['type' => 'integer', 'description' => 'Position among widgets'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }
}
