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
                    'description' => 'View Grav system logs with optional filtering by level (ERROR, WARNING, INFO, DEBUG) and text search, or list the other log files available (security.log, email.log, scheduler.log, ...) via `view: "files"`. [Requires: api.system.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['entries', 'files'], 'description' => 'Which view to return (default: "entries")'],
                            'file' => ['type' => 'string', 'description' => 'Log file to read (default: "grav.log"); must be one listed by view "files"'],
                            'level' => ['type' => 'string', 'enum' => ['ERROR', 'WARNING', 'INFO', 'DEBUG'], 'description' => 'Filter by log level'],
                            'search' => ['type' => 'string', 'description' => 'Search in log messages'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (($args['view'] ?? null) === 'files') {
                        return ApiBridge::fromResponse($api->request('GET', '/system/logs/files'));
                    }

                    return ApiBridge::fromResponse($api->request('GET', '/system/logs', [
                        'file' => $args['file'] ?? null,
                        'level' => $args['level'] ?? null,
                        'search' => $args['search'] ?? null,
                        'page' => $args['page'] ?? 1,
                        'per_page' => $args['per_page'] ?? 50,
                    ]));
                },
            ],

            'clear_log' => [
                // requireSuper() in the api controller = the 'admin.super' scope cap,
                // so scoped keys without that scope no longer see a tool they can't call.
                'permission' => 'admin.super',
                'descriptor' => [
                    'name' => 'clear_log',
                    'title' => 'Clear Log',
                    'description' => 'Truncate a log file in place and write one marker line naming who cleared it. Returns cleared_bytes. File must be one listed by get_logs `view: "files"` (super-admin only). [Requires: admin.super]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'file' => ['type' => 'string', 'description' => 'Log file to clear (default: "grav.log")'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request(
                    'DELETE',
                    '/system/logs',
                    [],
                    ['file' => $args['file'] ?? 'grav.log']
                )),
            ],

            'manage_backups' => [
                // The api plugin gates backups behind their own narrow permission
                // (credential-bearing archives, GHSA-2f86-9cp8-6hcf).
                'permission' => 'api.system.backup',
                'descriptor' => [
                    'name' => 'manage_backups',
                    'title' => 'Manage Backups',
                    'description' => 'List, create, or delete full backups of the Grav installation. A backup is a zip of the entire install (including user/accounts and user/config secrets) landing in the backup/ folder; list/create return filename, size, and date. [Requires: api.system.backup]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['list', 'create', 'delete'], 'description' => 'Action to perform'],
                            'filename' => ['type' => 'string', 'description' => 'Backup filename (for delete; a bare .zip name as listed)'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $filename = (string) ($args['filename'] ?? '');

                    return match ($args['action'] ?? null) {
                        'create' => ApiBridge::fromResponse($api->request('POST', '/system/backup')),
                        'delete' => $filename === ''
                            ? ApiBridge::toolJson(['error' => 'filename is required for action "delete".'])
                            : ApiBridge::fromResponse(
                                $api->request('DELETE', '/system/backups/' . rawurlencode($filename)),
                                successMessage: sprintf('Backup "%s" deleted.', $filename)
                            ),
                        'list' => ApiBridge::fromResponse($api->request('GET', '/system/backups')),
                        default => ApiBridge::toolError('Invalid action. Must be one of: list, create, delete'),
                    };
                },
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
                    'description' => 'Manually trigger a scheduler run. By default runs every enabled job that has missed its scheduled time ("overdue"); mode "due" runs only jobs scheduled for this exact minute, "all" runs every enabled job. Or pass `job` to run a single job by id (ids from get_scheduler). Reports which jobs ran and each one\'s outcome. [Requires: api.scheduler.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'mode' => ['type' => 'string', 'enum' => ['due', 'overdue', 'all'], 'description' => 'Which jobs to run (default: "overdue")'],
                            'job' => ['type' => 'string', 'description' => 'Run only this job id, regardless of schedule (mode is ignored)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $body = array_intersect_key($args, ['mode' => 1, 'job' => 1]);

                    return ApiBridge::fromResponse($api->request('POST', '/scheduler/run', [], $body ?: null));
                },
            ],

            'run_reports' => [
                'permission' => 'api.reports.read',
                'descriptor' => [
                    'name' => 'run_reports',
                    'title' => 'Run Reports',
                    'description' => 'Generate diagnostic reports. "all" (default) runs the standard reports: security checks, YAML linting, and any plugin-contributed reports, each with a status and list of items. "twig_content_scan" lists pages whose content contains Twig and the tokens the sandbox would refuse. "twig_content_page" gives one page\'s gate/sandbox/leak status and recent blocked tokens (needs `route`). "twig_sandbox_policy" shows the effective allowlists (built-in defaults plus the site\'s additions). [Requires: api.reports.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'report' => ['type' => 'string', 'enum' => ['all', 'twig_content_scan', 'twig_content_page', 'twig_sandbox_policy'], 'description' => 'Which report to run (default: "all")'],
                            'route' => ['type' => 'string', 'description' => 'Page route (required for "twig_content_page")'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $route = (string) ($args['route'] ?? '');

                    return match ($args['report'] ?? null) {
                        'twig_content_scan' => ApiBridge::fromResponse($api->request('GET', '/reports/twig-content/scan')),
                        'twig_content_page' => $route === ''
                            ? ApiBridge::toolJson(['error' => 'route is required for report "twig_content_page".'])
                            : ApiBridge::fromResponse($api->request('GET', '/reports/twig-content/page', ['route' => $route])),
                        'twig_sandbox_policy' => ApiBridge::fromResponse($api->request('GET', '/reports/twig-content/sandbox-policy')),
                        default => ApiBridge::fromResponse($api->request('GET', '/reports')),
                    };
                },
            ],

            'clear_twig_content_events' => [
                'permission' => 'api.system.write',
                'descriptor' => [
                    'name' => 'clear_twig_content_events',
                    'title' => 'Clear Twig Content Events',
                    'description' => 'Empty the Twig-in-Content diagnostics log — the record of tokens the sandbox refused in page content that run_reports ("twig_content_scan", "twig_content_page") reads. Use it once the flagged pages have been dealt with; the log is one site-wide record every admin shares, so clearing it removes the history for everyone. Returns the number of events cleared. [Requires: api.system.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse(
                    $api->request('DELETE', '/reports/twig-content/events')
                ),
            ],

            'get_audit_log' => [
                'permission' => 'api.super',
                'descriptor' => [
                    'name' => 'get_audit_log',
                    'title' => 'Get Audit Log',
                    'description' => 'The api plugin\'s audit trail (logins, content edits, user and config changes). Off by default — "events" and "facets" return 404 when `plugins.api.audit.enabled` is false and 503 when the server lacks SQLite; use "status" first. "facets" lists the distinct event names and actors for filtering. Super-admin only. [Requires: api.super]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['status', 'events', 'facets'], 'description' => 'Which view to return (default: "events")'],
                            'event' => ['type' => 'string', 'description' => 'Filter: exact event name'],
                            'actor' => ['type' => 'string', 'description' => 'Filter: matches actor id or name'],
                            'target_type' => ['type' => 'string', 'description' => 'Filter: target type'],
                            'severity' => ['type' => 'string', 'description' => 'Filter: severity'],
                            'from' => ['type' => 'integer', 'description' => 'Filter: start of time range, epoch milliseconds'],
                            'to' => ['type' => 'integer', 'description' => 'Filter: end of time range, epoch milliseconds'],
                            'q' => ['type' => 'string', 'description' => 'Free text over actor name, target id, event, ip'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    return match ($args['view'] ?? null) {
                        'status' => ApiBridge::fromResponse($api->request('GET', '/audit/status')),
                        'facets' => ApiBridge::fromResponse($api->request('GET', '/audit/facets')),
                        default => ApiBridge::fromResponse($api->request('GET', '/audit/events', [
                            'event' => $args['event'] ?? null,
                            'actor' => $args['actor'] ?? null,
                            'target_type' => $args['target_type'] ?? null,
                            'severity' => $args['severity'] ?? null,
                            'from' => $args['from'] ?? null,
                            'to' => $args['to'] ?? null,
                            'q' => $args['q'] ?? null,
                            'page' => $args['page'] ?? 1,
                            'per_page' => $args['per_page'] ?? 50,
                        ])),
                    };
                },
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

            'manage_environments' => [
                'permission' => 'api.config.write',
                'descriptor' => [
                    'name' => 'manage_environments',
                    'title' => 'Manage Environments',
                    'description' => 'Create or delete a `user/env/<name>/` folder for environment-scoped configuration overrides (used by update_config\'s `environment` arg). Environments are not created implicitly — clients must opt in. Delete removes the folder recursively; the api refuses to delete the environment currently serving the request. [Requires: api.config.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['create', 'delete'], 'description' => 'Action to perform'],
                            'name' => ['type' => 'string', 'description' => 'Environment name (folder under user/env/, e.g. "production", "staging")'],
                        ],
                        'required' => ['action', 'name'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $name = (string) ($args['name'] ?? '');

                    return ($args['action'] ?? null) === 'delete'
                        ? ApiBridge::fromResponse(
                            $api->request('DELETE', '/system/environments/' . rawurlencode($name)),
                            successMessage: sprintf('Environment "%s" deleted.', $name)
                        )
                        : ApiBridge::fromResponse($api->request(
                            'POST',
                            '/system/environments',
                            [],
                            ApiBridge::pick($args, ['name'])
                        ));
                },
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
                    'description' => 'Get dashboard info: site overview statistics (page counts, user counts, plugin/theme counts, media stats, last backup info), system notifications from getgrav.org, the Grav news feed, page-view popularity (today/week/month summary, 14-day chart, top pages), or a security exposure probe. The probe creates or reuses a sentinel file under user/data and returns its public `url` and `token`; fetch the url yourself — if the body equals the token, the user/ folder is exposed to the web. [Requires: api.system.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['stats', 'notifications', 'feed', 'popularity', 'security_probe'], 'description' => 'Which view to return (default: stats)'],
                            'force' => ['type' => 'boolean', 'description' => 'Refresh the news feed instead of using the cached copy (feed view only)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $view = $args['view'] ?? null;
                    if ($view === 'feed') {
                        return ApiBridge::fromResponse($api->request('GET', '/dashboard/feed', [
                            'force' => !empty($args['force']) ? 'true' : null,
                        ]));
                    }

                    $path = match ($view) {
                        'notifications' => '/dashboard/notifications',
                        'popularity' => '/dashboard/popularity',
                        'security_probe' => '/dashboard/security/exposure-probe',
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
