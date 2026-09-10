<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Webhooks domain: tools over grav-plugin-api's /webhooks endpoints.
 */
final class WebhooksTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'get_webhooks' => [
                'permission' => 'api.webhooks.read',
                'descriptor' => [
                    'name' => 'get_webhooks',
                    'title' => 'Get Webhooks',
                    'description' => 'List all configured webhooks with their URLs, events, active status, and failure count, or view the delivery log for one webhook, showing each attempt with status code, success, response time, and timestamp. [Requires: api.webhooks.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['list', 'deliveries'], 'description' => 'What to retrieve (default: list)'],
                            'webhook_id' => ['type' => 'string', 'description' => 'Webhook ID (required for deliveries view)'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1, deliveries view only)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50, deliveries view only)'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $webhookId = isset($args['webhook_id']) ? (string) $args['webhook_id'] : '';

                    return match ($args['view'] ?? 'list') {
                        'list' => ApiBridge::fromResponse($api->request('GET', '/webhooks')),
                        'deliveries' => $webhookId === ''
                            ? ApiBridge::toolJson(['error' => 'webhook_id is required for deliveries view'])
                            : ApiBridge::fromResponse($api->request(
                                'GET',
                                '/webhooks/' . rawurlencode($webhookId) . '/deliveries',
                                [
                                    'page' => $args['page'] ?? 1,
                                    'per_page' => $args['per_page'] ?? 50,
                                ]
                            )),
                        default => ApiBridge::toolError('Invalid view. Must be one of: list, deliveries'),
                    };
                },
            ],

            'manage_webhook' => [
                'permission' => 'api.webhooks.write',
                'descriptor' => [
                    'name' => 'manage_webhook',
                    'title' => 'Manage Webhook',
                    'description' => 'Create, update, delete, or test a webhook. Test sends a test payload to a webhook endpoint to verify it is receiving and processing correctly. [Requires: api.webhooks.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['create', 'update', 'delete', 'test'], 'description' => 'Action to perform'],
                            'id' => ['type' => 'string', 'description' => 'Webhook ID (required for update, delete, and test)'],
                            'url' => ['type' => 'string', 'description' => 'Webhook endpoint URL (required for create)'],
                            'events' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Events to subscribe to: page.created/updated/deleted/moved/translated, pages.reordered, media.uploaded/deleted, user.created/updated/deleted, config.updated, gpm.installed/removed, grav.upgraded'],
                            'active' => ['type' => 'boolean', 'description' => 'Whether the webhook is active'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $id = isset($args['id']) ? (string) $args['id'] : '';

                    return match ($args['action'] ?? null) {
                        'create' => ApiBridge::fromResponse($api->request(
                            'POST',
                            '/webhooks',
                            [],
                            ApiBridge::pick($args, ['url', 'events', 'active'])
                        )),
                        'update' => $id === ''
                            ? ApiBridge::toolJson(['error' => 'id is required for update action'])
                            : ApiBridge::fromResponse($api->request(
                                'PATCH',
                                '/webhooks/' . rawurlencode($id),
                                [],
                                ApiBridge::pick($args, ['url', 'events', 'active'])
                            )),
                        'delete' => $id === ''
                            ? ApiBridge::toolJson(['error' => 'id is required for delete action'])
                            : self::delete($api, $id),
                        'test' => $id === ''
                            ? ApiBridge::toolJson(['error' => 'id is required for test action'])
                            : ApiBridge::fromResponse($api->request('POST', '/webhooks/' . rawurlencode($id) . '/test')),
                        default => ApiBridge::toolError('Invalid action. Must be one of: create, update, delete, test'),
                    };
                },
            ],
        ];
    }

    private static function delete(ApiBridge $api, string $id): array
    {
        return ApiBridge::fromResponse(
            $api->request('DELETE', '/webhooks/' . rawurlencode($id)),
            successMessage: sprintf('Webhook "%s" deleted.', $id)
        );
    }
}
