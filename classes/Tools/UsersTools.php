<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Users domain: tools over grav-plugin-api's /users endpoints (account CRUD
 * plus per-user API key lifecycle).
 */
final class UsersTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'get_users' => [
                'permission' => 'api.users.read',
                'descriptor' => [
                    'name' => 'get_users',
                    'title' => 'Get Users',
                    'description' => 'List user accounts, or get one user\'s full details (including access permissions and groups) when "username" is given. [Requires: api.users.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'username' => ['type' => 'string', 'description' => 'Username to look up; omit to list users'],
                            'search' => ['type' => 'string', 'description' => 'Search users by username, email, or name (listing only)'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1, listing only)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50, listing only)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (($args['username'] ?? '') !== '') {
                        return ApiBridge::fromResponse(
                            $api->request('GET', '/users/' . self::username($args)),
                            true
                        );
                    }

                    return ApiBridge::fromResponse($api->request('GET', '/users', [
                        'search' => $args['search'] ?? null,
                        'page' => $args['page'] ?? 1,
                        'per_page' => $args['per_page'] ?? 50,
                    ]));
                },
            ],

            'manage_users' => [
                'permission' => 'api.users.write',
                'descriptor' => [
                    'name' => 'manage_users',
                    'title' => 'Manage Users',
                    'description' => 'Create, update, or delete a user account. For "create": password and email are required too. For "update": only provided fields are changed. For "delete": you cannot delete your own account. [Requires: api.users.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete'], 'description' => 'Action to perform'],
                            'username' => ['type' => 'string', 'description' => 'Username to create (alphanumeric, no spaces), update, or delete'],
                            'password' => ['type' => 'string', 'description' => 'Password (required for "create", new password for "update")'],
                            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Email address (required for "create")'],
                            'fullname' => ['type' => 'string', 'description' => 'Full display name'],
                            'title' => ['type' => 'string', 'description' => 'Title/role description'],
                            'state' => ['type' => 'string', 'enum' => ['enabled', 'disabled'], 'description' => 'Account state'],
                            'access' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Permission access map'],
                            'etag' => ['type' => 'string', 'description' => 'ETag for conflict detection (for "update")'],
                        ],
                        'required' => ['action', 'username'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (($args['username'] ?? '') === '') {
                        return ApiBridge::toolJson(['error' => 'username is required for the create, update, and delete actions']);
                    }

                    return match ($args['action'] ?? null) {
                        'create' => ApiBridge::fromResponse($api->request(
                            'POST',
                            '/users',
                            [],
                            ApiBridge::pick($args, ['username', 'password', 'email', 'fullname', 'title', 'state', 'access'])
                        )),
                        // withEtag deliberately false — the update action doesn't round-trip ETags.
                        'update' => ApiBridge::fromResponse($api->request(
                            'PATCH',
                            '/users/' . self::username($args),
                            [],
                            ApiBridge::pick($args, ['email', 'fullname', 'title', 'state', 'password', 'access']),
                            isset($args['etag']) ? ['If-Match' => (string) $args['etag']] : []
                        )),
                        'delete' => ApiBridge::fromResponse(
                            $api->request('DELETE', '/users/' . self::username($args)),
                            successMessage: sprintf('User "%s" deleted.', $args['username'] ?? '')
                        ),
                        default => ApiBridge::toolError('Invalid action. Must be one of: create, update, delete'),
                    };
                },
            ],

            'manage_api_keys' => [
                'permission' => 'api.users.write',
                'descriptor' => [
                    'name' => 'manage_api_keys',
                    'title' => 'Manage API Keys',
                    'description' => 'List, create, or revoke API keys for a user. For "create": returns the key value once — save it immediately. For "revoke": provide the key_id. [Requires: api.users.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'username' => ['type' => 'string', 'description' => 'Username whose API keys to manage'],
                            'action' => ['type' => 'string', 'enum' => ['list', 'create', 'revoke'], 'description' => 'Action to perform'],
                            'name' => ['type' => 'string', 'description' => 'Name for new key (required for "create")'],
                            'expiry_days' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Days until expiry (for "create")'],
                            'key_id' => ['type' => 'string', 'description' => 'Key ID to revoke (required for "revoke")'],
                        ],
                        'required' => ['username', 'action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $username = self::username($args);
                    $action = $args['action'] ?? null;

                    if ($action === 'list') {
                        return ApiBridge::fromResponse($api->request('GET', "/users/{$username}/api-keys"));
                    }

                    if ($action === 'create') {
                        $body = ['name' => $args['name'] ?? 'MCP-generated key'];
                        if (isset($args['expiry_days'])) {
                            $body['expiry_days'] = $args['expiry_days'];
                        }

                        return ApiBridge::fromResponse(
                            $api->request('POST', "/users/{$username}/api-keys", [], $body),
                            false,
                            static fn(array $data): array => $data + ['_warning' => 'This API key is shown only once. Save it now.']
                        );
                    }

                    if ($action === 'revoke') {
                        if (!isset($args['key_id'])) {
                            // Plain (non-isError) result, like manage_webhook's arg validation.
                            return ApiBridge::toolJson(['error' => 'key_id is required for revoke action']);
                        }

                        $keyId = (string) $args['key_id'];

                        return ApiBridge::fromResponse(
                            $api->request('DELETE', "/users/{$username}/api-keys/" . rawurlencode($keyId)),
                            successMessage: sprintf('API key "%s" revoked.', $keyId)
                        );
                    }

                    return ApiBridge::toolError('Invalid action: must be one of list, create, revoke.');
                },
            ],
        ];
    }

    /** Username arg → URL path segment, percent-encoded. */
    private static function username(array $args): string
    {
        return rawurlencode((string) ($args['username'] ?? ''));
    }
}
