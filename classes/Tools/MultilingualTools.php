<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Multilingual domain: tools over grav-plugin-api's /languages and
 * /pages/{route}/... endpoints.
 */
final class MultilingualTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'list_languages' => [
                'permission' => 'api.pages.read',
                'descriptor' => [
                    'name' => 'list_languages',
                    'title' => 'List Languages',
                    'description' => 'List all configured site languages with codes, names, and which is the default. [Requires: api.pages.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request('GET', '/languages')),
            ],

            'get_page_translations' => [
                'permission' => 'api.pages.read',
                'descriptor' => [
                    'name' => 'get_page_translations',
                    'title' => 'Get Page Translations',
                    'description' => 'Show which language translations exist and which are missing for a page. Provide both source_lang and target_lang to instead compare those two language versions side-by-side (content and frontmatter differences). [Requires: api.pages.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'route' => ['type' => 'string', 'description' => 'Page route to check translations for'],
                            'source_lang' => ['type' => 'string', 'description' => 'Source language code; provide together with target_lang to compare two versions'],
                            'target_lang' => ['type' => 'string', 'description' => 'Target language code to compare against; provide together with source_lang to compare two versions'],
                        ],
                        'required' => ['route'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $hasSource = isset($args['source_lang']) && $args['source_lang'] !== '';
                    $hasTarget = isset($args['target_lang']) && $args['target_lang'] !== '';

                    if ($hasSource !== $hasTarget) {
                        return ApiBridge::toolJson(['error' => 'Both source_lang and target_lang are required to compare translations.']);
                    }

                    return $hasSource
                        ? ApiBridge::fromResponse($api->request(
                            'GET',
                            '/pages/' . ApiBridge::path($args) . '/compare',
                            [
                                'source' => $args['source_lang'] ?? null,
                                'target' => $args['target_lang'] ?? null,
                            ]
                        ))
                        : ApiBridge::fromResponse($api->request('GET', '/pages/' . ApiBridge::path($args) . '/languages'));
                },
            ],

            'manage_page_translation' => [
                'permission' => 'api.pages.write',
                'descriptor' => [
                    'name' => 'manage_page_translation',
                    'title' => 'Manage Page Translation',
                    'description' => 'Create a new language translation for a page, or adopt an untyped base page file (e.g. `default.md`) as a specific language by renaming it to `{template}.{lang}.md` (content untouched; fails if that language already has a file, or no untyped base exists). [Requires: api.pages.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['create', 'adopt'], 'description' => 'Action to perform'],
                            'route' => ['type' => 'string', 'description' => 'Page route'],
                            'language' => ['type' => 'string', 'description' => 'Target language code (e.g. "fr", "de", "es")'],
                            'title' => ['type' => 'string', 'description' => 'Translated title (create action only)'],
                            'content' => ['type' => 'string', 'description' => 'Translated content in markdown (create action only)'],
                            'header' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Translated header/frontmatter fields (create action only)'],
                        ],
                        'required' => ['route', 'action', 'language'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    return match ($args['action'] ?? null) {
                        'create' => ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/' . ApiBridge::path($args) . '/translate',
                            [],
                            // The MCP-facing name is 'language'; the endpoint reads 'lang'.
                            ['lang' => $args['language'] ?? ''] + ApiBridge::pick($args, ['title', 'content', 'header'])
                        )),
                        'adopt' => ApiBridge::fromResponse($api->request(
                            'POST',
                            '/pages/' . ApiBridge::path($args) . '/adopt-language',
                            [],
                            // The MCP-facing name is 'language'; the endpoint reads 'lang'.
                            ['lang' => $args['language'] ?? '']
                        )),
                        default => ApiBridge::toolError('Invalid action: must be one of "create", "adopt".'),
                    };
                },
            ],
        ];
    }
}
