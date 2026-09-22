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
            'get_groups' => [
                'permission' => 'api.users.read',
                'descriptor' => [
                    'name' => 'get_groups',
                    'title' => 'Get Groups',
                    'description' => 'List permission groups, or get one group\'s full details (including its access permission map) when "name" is given. [Requires: api.users.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Group name to look up; omit to list groups'],
                            'search' => ['type' => 'string', 'description' => 'Search groups by name (listing only)'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1, listing only)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50, listing only)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (($args['name'] ?? '') !== '') {
                        return ApiBridge::fromResponse(
                            $api->request('GET', '/groups/' . rawurlencode((string) $args['name'])),
                            true
                        );
                    }

                    return ApiBridge::fromResponse($api->request('GET', '/groups', [
                        'search' => $args['search'] ?? null,
                        'page' => $args['page'] ?? 1,
                        'per_page' => $args['per_page'] ?? 50,
                    ]));
                },
            ],

            'manage_groups' => [
                // requireSuper() in the api controller = the 'admin.super' scope cap,
                // so scoped keys without that scope no longer see a tool they can't call.
                'permission' => 'admin.super',
                'descriptor' => [
                    'name' => 'manage_groups',
                    'title' => 'Manage Groups',
                    'description' => 'Create, update, or delete a permission group (super-admin only). "access" is stored verbatim and is REPLACED WHOLESALE on update, so send the complete map — review it carefully, since a group can grant api.super. A group cannot be renamed. Deleting a group does not check whether any account still references it. [Requires: admin.super]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete'], 'description' => 'Action to perform'],
                            'name' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,200}$', 'description' => 'Group name to create, update, or delete (cannot be changed once created)'],
                            'readable_name' => ['type' => 'string', 'description' => 'Human-readable display name'],
                            'description' => ['type' => 'string', 'description' => 'Group description'],
                            'icon' => ['type' => 'string', 'description' => 'Icon identifier'],
                            'enabled' => ['type' => 'boolean', 'description' => 'Whether the group is enabled (default: true on create)'],
                            'access' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Full permission access map, e.g. {"api":{"pages":{"read":true}}} — on update this replaces the existing map wholesale, so send the complete map'],
                            'etag' => ['type' => 'string', 'description' => 'ETag for conflict detection (for "update")'],
                        ],
                        'required' => ['action', 'name'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    if (($args['name'] ?? '') === '') {
                        return ApiBridge::toolJson(['error' => 'name is required for the create, update, and delete actions']);
                    }

                    $name = rawurlencode((string) $args['name']);

                    if (($args['action'] ?? null) === 'update') {
                        $body = self::groupFields($args);
                        if ($body === []) {
                            return ApiBridge::toolJson(['error' => 'update needs at least one of readable_name, description, icon, enabled, access']);
                        }

                        return ApiBridge::fromResponse($api->request(
                            'PATCH',
                            '/groups/' . $name,
                            [],
                            $body,
                            isset($args['etag']) ? ['If-Match' => (string) $args['etag']] : []
                        ));
                    }

                    return match ($args['action'] ?? null) {
                        'create' => ApiBridge::fromResponse($api->request(
                            'POST',
                            '/groups',
                            [],
                            ['groupname' => $args['name']] + self::groupFields($args)
                        )),
                        'delete' => ApiBridge::fromResponse(
                            $api->request('DELETE', '/groups/' . $name),
                            successMessage: sprintf('Group "%s" deleted.', $args['name'] ?? '')
                        ),
                        default => ApiBridge::toolError('Invalid action. Must be one of: create, update, delete'),
                    };
                },
            ],

            'manage_invitations' => [
                'permission' => 'api.users.write',
                'descriptor' => [
                    'name' => 'manage_invitations',
                    'title' => 'Manage Invitations',
                    'description' => 'List, create, delete, or resend a user invitation. For "create": "email" is required; returns the raw token and an accept link — hand the link to the invitee. Only one pending invitation per email is kept; a new one replaces it. "expiration" is in seconds and defaults to 7 days; values under 300 fall back to the default and api plugin 1.0.38+ caps it at 30 days. Callers who are not super-admin have "groups" dropped and any super flag in "access" stripped silently. "resend" 404s for an expired invitation and 422s when site email isn\'t configured. "list" also purges expired invitations. [Requires: api.users.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['list', 'create', 'delete', 'resend'], 'description' => 'Action to perform'],
                            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Invitee email (required for "create")'],
                            'fullname' => ['type' => 'string', 'description' => 'Invitee full name'],
                            'access' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Permission access map to grant on acceptance'],
                            'groups' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Group names to assign on acceptance (super-admin callers only)'],
                            'expiration' => ['type' => 'integer', 'minimum' => 300, 'description' => 'Seconds until the invitation expires (default: 7 days)'],
                            'message' => ['type' => 'string', 'description' => 'Message included in the invitation email (or in the "resend" email)'],
                            'token' => ['type' => 'string', 'description' => 'Invitation token (required for "delete", "resend")'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    return match ($args['action'] ?? null) {
                        'list' => ApiBridge::fromResponse($api->request('GET', '/invitations')),
                        'create' => (($args['email'] ?? '') === '')
                            ? ApiBridge::toolJson(['error' => 'email is required for the create action'])
                            : ApiBridge::fromResponse($api->request(
                                'POST',
                                '/invitations',
                                [],
                                ApiBridge::pick($args, ['email', 'fullname', 'access', 'groups', 'expiration', 'message'])
                            )),
                        'delete' => (($args['token'] ?? '') === '')
                            ? ApiBridge::toolJson(['error' => 'token is required for the delete action'])
                            : ApiBridge::fromResponse(
                                $api->request('DELETE', '/invitations/' . rawurlencode((string) $args['token'])),
                                successMessage: 'Invitation deleted.'
                            ),
                        'resend' => (($args['token'] ?? '') === '')
                            ? ApiBridge::toolJson(['error' => 'token is required for the resend action'])
                            : ApiBridge::fromResponse($api->request(
                                'POST',
                                '/invitations/' . rawurlencode((string) $args['token']) . '/resend',
                                [],
                                ApiBridge::pick($args, ['message']) ?: null
                            )),
                        default => ApiBridge::toolError('Invalid action. Must be one of: list, create, delete, resend'),
                    };
                },
            ],
        ];
    }

    /** Username arg → URL path segment, percent-encoded. */
    private static function username(array $args): string
    {
        return rawurlencode((string) ($args['username'] ?? ''));
    }

    /** Group readable_name/description/icon/enabled/access → api field names, only those present in $args. */
    private static function groupFields(array $args): array
    {
        $body = ApiBridge::pick($args, ['description', 'icon', 'enabled', 'access']);
        if (array_key_exists('readable_name', $args)) {
            $body['readableName'] = $args['readable_name']; // MCP name differs from the api field
        }

        return $body;
    }
}
