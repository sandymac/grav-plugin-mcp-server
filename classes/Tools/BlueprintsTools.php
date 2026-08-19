<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;

/**
 * Blueprints domain: tools over grav-plugin-api's /blueprints, /taxonomy, and
 * /blueprint-upload endpoints.
 */
final class BlueprintsTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'list_page_templates' => [
                'permission' => 'api.pages.read',
                'descriptor' => [
                    'name' => 'list_page_templates',
                    'title' => 'List Page Templates',
                    'description' => 'List all available page templates/blueprints. Each template defines a page type with its own set of fields. Use this before creating pages to know which templates are available. [Requires: api.pages.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/blueprints/pages')),
            ],

            'get_blueprint' => [
                // Five routes, three permissions (pages.read / config.read / access,
                // by blueprint type). Declare the weakest so any key that can use
                // some variant sees the tool; the api plugin enforces per route.
                'permission' => 'api.access',
                'descriptor' => [
                    'name' => 'get_blueprint',
                    'title' => 'Get Blueprint',
                    'description' => 'Get the full field schema (blueprint) for a page template, plugin config, theme config, user accounts, or system config. [Requires: api.pages.read for "page", api.config.read for "plugin"/"theme"/"config", api.access for "user"]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['page', 'plugin', 'theme', 'user', 'config'], 'description' => 'Blueprint type'],
                            'name' => ['type' => 'string', 'description' => 'Blueprint name: template name for "page", plugin/theme slug for "plugin"/"theme", "users" for "user", scope for "config"'],
                        ],
                        'required' => ['type', 'name'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $name = ApiBridge::path($args, 'name');
                    $path = match ($args['type'] ?? null) {
                        'page' => '/blueprints/pages/' . $name,
                        'plugin' => '/blueprints/plugins/' . $name,
                        'theme' => '/blueprints/themes/' . $name,
                        'user' => '/blueprints/users',
                        'config' => '/blueprints/config/' . $name,
                        default => null,
                    };

                    if ($path === null) {
                        return ApiBridge::toolError('Invalid type: must be one of "page", "plugin", "theme", "user", "config".');
                    }

                    return ApiBridge::fromResponse($api->request('GET', $path));
                },
            ],

            'get_permissions' => [
                'permission' => 'api.users.read',
                'descriptor' => [
                    'name' => 'get_permissions',
                    'title' => 'Get Permissions',
                    'description' => 'Get all registered permission actions and their hierarchy. Useful for understanding what access levels exist and configuring user permissions. [Requires: api.users.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/blueprints/users/permissions')),
            ],

            'get_taxonomy' => [
                'permission' => 'api.pages.read',
                'descriptor' => [
                    'name' => 'get_taxonomy',
                    'title' => 'Get Taxonomy',
                    'description' => 'Get all taxonomy types and their current values used across the site. Taxonomy types (like "category", "tag") are defined in site config; values come from pages. [Requires: api.pages.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/taxonomy')),
            ],

            'manage_blueprint_file' => [
                'permission' => 'api.media.write',
                'descriptor' => [
                    'name' => 'manage_blueprint_file',
                    'title' => 'Manage Blueprint File',
                    'description' => 'Upload or delete a file referenced by a blueprint file/upload field (theme/plugin config form, account avatar, etc.). Upload returns the user-rooted path to pass to a later delete action; delete is idempotent. [Requires: api.media.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['upload', 'delete'], 'description' => 'Action to perform'],
                            'destination' => ['type' => 'string', 'description' => 'Blueprint destination (stream, self@:subpath, or relative user-rooted path) (required for upload)'],
                            'scope' => ['type' => 'string', 'description' => 'Owning scope: plugins/<slug>, themes/<slug>, pages/<route>, or users/<username> (required for upload)'],
                            'filename' => ['type' => 'string', 'description' => 'Filename for the uploaded file (required for upload)'],
                            'content_base64' => ['type' => 'string', 'description' => 'File contents, base64-encoded (required for upload)'],
                            'content_type' => ['type' => 'string', 'description' => 'MIME type, auto-detected from extension if omitted (upload only)'],
                            'path' => ['type' => 'string', 'description' => 'User-rooted logical path returned from a prior upload action (required for delete)'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    return match ($args['action'] ?? null) {
                        'upload' => self::uploadBlueprintFile($api, $args),
                        'delete' => self::deleteBlueprintFile($api, $args),
                        default => ApiBridge::toolError('Invalid action. Must be one of: upload, delete'),
                    };
                },
            ],
        ];
    }

    private static function uploadBlueprintFile(ApiBridge $api, array $args): array
    {
        foreach (['destination', 'scope', 'filename', 'content_base64'] as $field) {
            if (!isset($args[$field]) || $args[$field] === '') {
                return ApiBridge::toolJson(['error' => $field . ' is required for upload action']);
            }
        }

        $content = base64_decode((string) $args['content_base64'], true);
        if ($content === false) {
            return ApiBridge::toolError('content_base64 is not valid base64.');
        }

        $uploadedFile = new UploadedFile(
            Stream::create($content),
            strlen($content),
            UPLOAD_ERR_OK,
            (string) $args['filename'],
            (string) ($args['content_type'] ?? MediaTools::guessMimeType((string) $args['filename']))
        );

        return ApiBridge::fromResponse($api->request(
            'POST',
            '/blueprint-upload',
            [],
            [
                'destination' => $args['destination'],
                'scope' => $args['scope'],
            ],
            [],
            ['file' => $uploadedFile]
        ));
    }

    private static function deleteBlueprintFile(ApiBridge $api, array $args): array
    {
        if (!isset($args['path']) || $args['path'] === '') {
            return ApiBridge::toolJson(['error' => 'path is required for delete action']);
        }

        $path = (string) $args['path'];
        $resp = $api->request('DELETE', '/blueprint-upload', [], ['path' => $path]);

        return $resp['status'] >= 400
            ? ApiBridge::fromResponse($resp)
            : ApiBridge::toolJson(['success' => true, 'path' => $path]);
    }
}
