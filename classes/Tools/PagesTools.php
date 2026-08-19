<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Pages domain: tools over grav-plugin-api's /pages endpoints.
 */
final class PagesTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'list_pages' => [
                'permission' => 'api.pages.read',
                'descriptor' => [
                    'name' => 'list_pages',
                    'title' => 'List Pages',
                    'description' => 'List and search CMS pages with filtering by template, published status, visibility, parent route, and full-text search. Supports sorting and pagination. Returns page summaries. [Requires: api.pages.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => ['type' => 'string', 'description' => 'Full-text search query'],
                            'template' => ['type' => 'string', 'description' => 'Filter by page template (e.g. "blog", "item")'],
                            'published' => ['type' => 'boolean', 'description' => 'Filter by published status'],
                            'visible' => ['type' => 'boolean', 'description' => 'Filter by visibility'],
                            'routable' => ['type' => 'boolean', 'description' => 'Filter by routable status'],
                            'parent' => ['type' => 'string', 'description' => 'Filter by parent route (e.g. "/blog")'],
                            'children_of' => ['type' => 'string', 'description' => 'Get direct children of this route'],
                            'root' => ['type' => 'boolean', 'description' => 'Only return root-level pages'],
                            'sort' => ['type' => 'string', 'enum' => ['date', 'title', 'slug', 'modified', 'order', 'default'], 'description' => 'Sort field'],
                            'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Sort order'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Items per page (default: 50)'],
                            'lang' => ['type' => 'string', 'description' => 'Language code for multilingual sites'],
                            'translations' => ['type' => 'boolean', 'description' => 'Include translation info'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/pages', [
                    'search' => $args['search'] ?? null,
                    'template' => $args['template'] ?? null,
                    'published' => $args['published'] ?? null,
                    'visible' => $args['visible'] ?? null,
                    'routable' => $args['routable'] ?? null,
                    'parent' => $args['parent'] ?? null,
                    'children_of' => $args['children_of'] ?? null,
                    'root' => $args['root'] ?? null,
                    'sort' => $args['sort'] ?? null,
                    'order' => $args['order'] ?? null,
                    'page' => $args['page'] ?? 1,
                    'per_page' => $args['per_page'] ?? 50,
                    'lang' => $args['lang'] ?? null,
                    'translations' => $args['translations'] ?? null,
                ])),
            ],

            'get_page' => [
                'permission' => 'api.pages.read',
                'descriptor' => [
                    'name' => 'get_page',
                    'title' => 'Get Page',
                    'description' => 'Get full details of a single page including content (markdown or rendered HTML), header/frontmatter fields, media files, and taxonomy. Options to include children and translation info. [Requires: api.pages.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route (e.g. "/blog/my-post")'],
                            'render' => ['type' => 'boolean', 'description' => 'Return rendered HTML instead of raw markdown'],
                            'children' => ['type' => 'boolean', 'description' => 'Include child pages'],
                            'children_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Depth for child pages (default: 1)'],
                            'lang' => ['type' => 'string', 'description' => 'Language code'],
                            'translations' => ['type' => 'boolean', 'description' => 'Include translation info'],
                        ],
                        'required' => ['route'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse(
                    $api->request('GET', '/pages/' . ApiBridge::path($args), [
                        'render' => $args['render'] ?? null,
                        'children' => $args['children'] ?? null,
                        'children_depth' => $args['children_depth'] ?? null,
                        'lang' => $args['lang'] ?? null,
                        'translations' => $args['translations'] ?? null,
                    ]),
                    true
                ),
            ],

            'create_page' => [
                'permission' => 'api.pages.write',
                'descriptor' => [
                    'name' => 'create_page',
                    'title' => 'Create Page',
                    'description' => 'Create a new page at the specified route. Requires title and route at minimum. You can set template, content (markdown), header/frontmatter fields, visibility, and ordering. [Requires: api.pages.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route (e.g. "/blog/new-post")'],
                            'title' => ['type' => 'string', 'description' => 'Page title'],
                            'content' => ['type' => 'string', 'description' => 'Page content in markdown'],
                            'template' => ['type' => 'string', 'description' => 'Page template (default: "default")'],
                            'header' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Frontmatter/header fields as key-value pairs'],
                            'visible' => ['type' => 'boolean', 'description' => 'Make page visible in navigation'],
                            'order' => ['type' => 'integer', 'description' => 'Numeric ordering value'],
                            'lang' => ['type' => 'string', 'description' => 'Language for the page'],
                        ],
                        'required' => ['route', 'title'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $body = ApiBridge::pick($args, ['route', 'title', 'content', 'template', 'header', 'order', 'lang']);
                    // create() has no top-level visible handling (update() does);
                    // header.visible is the knob that actually exists on create.
                    if (array_key_exists('visible', $args)) {
                        $body['header'] = (array) ($body['header'] ?? []);
                        $body['header']['visible'] = (bool) $args['visible'];
                    }
                    return ApiBridge::fromResponse($api->request('POST', '/pages', [], $body));
                },
            ],

            'update_page' => [
                'permission' => 'api.pages.write',
                'descriptor' => [
                    'name' => 'update_page',
                    'title' => 'Update Page',
                    'description' => 'Update an existing page. Only the fields you provide will be changed — header fields are deep-merged. Pass etag (from get_page) for conflict detection; if omitted, overwrites without checking. [Requires: api.pages.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route to update'],
                            'title' => ['type' => 'string', 'description' => 'New page title'],
                            'content' => ['type' => 'string', 'description' => 'New content in markdown'],
                            'template' => ['type' => 'string', 'description' => 'Change page template'],
                            'header' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Header fields to merge (deep-merged with existing)'],
                            'published' => ['type' => 'boolean', 'description' => 'Set published status'],
                            'visible' => ['type' => 'boolean', 'description' => 'Set visibility'],
                            'lang' => ['type' => 'string', 'description' => 'Language to update'],
                            'etag' => ['type' => 'string', 'description' => 'ETag from get_page for optimistic concurrency'],
                        ],
                        'required' => ['route'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse(
                    $api->request(
                        'PATCH',
                        '/pages/' . ApiBridge::path($args),
                        // update() reads lang from the query string, not the body.
                        ['lang' => $args['lang'] ?? null],
                        ApiBridge::pick($args, ['title', 'content', 'template', 'header', 'published', 'visible']),
                        isset($args['etag']) ? ['If-Match' => (string) $args['etag']] : []
                    ),
                    true
                ),
            ],

            'delete_page' => [
                'permission' => 'api.pages.write',
                'descriptor' => [
                    'name' => 'delete_page',
                    'title' => 'Delete Page',
                    'description' => 'Delete a page. By default deletes the page and all children. Set lang to delete only a specific language translation. [Requires: api.pages.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route to delete'],
                            'lang' => ['type' => 'string', 'description' => 'Delete only this language translation'],
                        ],
                        'required' => ['route'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse(
                    $api->request('DELETE', '/pages/' . ApiBridge::path($args), ['lang' => $args['lang'] ?? null]),
                    successMessage: sprintf('Page "%s" deleted.', $args['route'] ?? '')
                ),
            ],

            'transfer_page' => [
                'permission' => 'api.pages.write',
                'descriptor' => [
                    'name' => 'transfer_page',
                    'title' => 'Transfer Page',
                    'description' => 'Move or copy one page, selected with mode. mode=move relocates the page to a new parent and/or renames its slug — the page and all its children move together. mode=copy duplicates the page (with all its media files) to a new route, leaving the original in place. [Requires: api.pages.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'mode' => ['type' => 'string', 'enum' => ['move', 'copy'], 'description' => 'Whether to move the page or duplicate it'],
                            'route' => ['type' => 'string', 'description' => 'Page route to transfer (source route for mode=copy)'],
                            'parent' => ['type' => 'string', 'description' => 'New parent route, e.g. "/blog" (required for mode=move)'],
                            'slug' => ['type' => 'string', 'description' => 'New slug, if renaming (mode=move only)'],
                            'order' => ['type' => 'integer', 'description' => 'Position among siblings (mode=move only)'],
                            'destination_route' => ['type' => 'string', 'description' => 'Destination route for the copy (required for mode=copy)'],
                        ],
                        'required' => ['mode', 'route'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => match ($args['mode'] ?? null) {
                    'move' => ($args['parent'] ?? '') === ''
                        ? ApiBridge::toolJson(['error' => 'parent is required for the move mode'])
                        : ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/' . ApiBridge::path($args) . '/move',
                            [],
                            ApiBridge::pick($args, ['parent', 'slug', 'order'])
                        )),
                    'copy' => ($args['destination_route'] ?? '') === ''
                        ? ApiBridge::toolJson(['error' => 'destination_route is required for the copy mode'])
                        : ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/' . ApiBridge::path($args) . '/copy',
                            [],
                            ['route' => $args['destination_route'] ?? '']
                        )),
                    default => ApiBridge::toolError('Invalid mode. Must be one of: move, copy'),
                },
            ],

            'bulk_pages' => [
                'permission' => 'api.pages.write',
                'descriptor' => [
                    'name' => 'bulk_pages',
                    'title' => 'Bulk Page Operations',
                    'description' => 'Operate on many pages at once, selected with action. "batch" applies one operation (publish, unpublish, delete or copy) to an arbitrary list of up to 50 page routes anywhere in the tree. "reorder" sets the sibling order of the child pages under a single parent page, by listing their slugs in the desired sequence. "reorganize" atomically re-parents and/or repositions up to 50 pages in one call, each operation naming its own route, new parent and position; all moves are validated before any is applied. [Requires: api.pages.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['batch', 'reorder', 'reorganize'], 'description' => 'Which bulk operation to perform'],
                            'operation' => ['type' => 'string', 'enum' => ['publish', 'unpublish', 'delete', 'copy'], 'description' => 'Operation to apply to every route (required for the batch action)'],
                            'routes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 1,
                                'maxItems' => 50,
                                'description' => 'Array of page routes to operate on (required for the batch action)',
                            ],
                            'parent_route' => ['type' => 'string', 'description' => 'Parent page route whose children to reorder (required for the reorder action)'],
                            'order' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'minItems' => 1,
                                'description' => 'Array of child slugs in desired order (required for the reorder action)',
                            ],
                            'operations' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'route' => ['type' => 'string', 'description' => 'Page route to move'],
                                        'parent' => ['type' => 'string', 'description' => 'New parent route'],
                                        'position' => ['type' => 'integer', 'description' => 'Position among siblings'],
                                    ],
                                    'required' => ['route'],
                                    'additionalProperties' => false,
                                ],
                                'minItems' => 1,
                                'maxItems' => 50,
                                'description' => 'Array of move operations (required for the reorganize action)',
                            ],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => match ($args['action'] ?? null) {
                    'batch' => !isset($args['operation'], $args['routes'])
                        ? ApiBridge::toolJson(['error' => 'operation and routes are required for the batch action'])
                        : ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/batch',
                            [],
                            ApiBridge::pick($args, ['operation', 'routes'])
                        )),
                    'reorder' => !isset($args['parent_route'], $args['order'])
                        ? ApiBridge::toolJson(['error' => 'parent_route and order are required for the reorder action'])
                        : ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/' . ApiBridge::path($args, 'parent_route') . '/reorder',
                            [],
                            ['order' => $args['order']]
                        )),
                    'reorganize' => !isset($args['operations'])
                        ? ApiBridge::toolJson(['error' => 'operations is required for the reorganize action'])
                        : ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/reorganize',
                            [],
                            ['operations' => $args['operations']]
                        )),
                    default => ApiBridge::toolError('Invalid action. Must be one of: batch, reorder, reorganize'),
                },
            ],
        ];
    }
}
