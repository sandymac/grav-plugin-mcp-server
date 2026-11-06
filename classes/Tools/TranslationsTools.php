<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\Tools;

use Grav\Plugin\McpServer\ApiBridge;

/**
 * Translations domain: tools over grav-plugin-api's /i18n endpoints — the
 * interface-string (language file) editor, not page translations.
 */
final class TranslationsTools
{
    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    public static function tools(): array
    {
        return [
            'get_translations' => [
                'permission' => 'api.translations.read',
                'descriptor' => [
                    'name' => 'get_translations',
                    'title' => 'Get Translations',
                    'description' => 'Interface strings (language files), not page translations. "languages": configured languages and which have local overrides. "sources": providers shipping strings for a language (system:core, plugin:<slug>, theme:<slug>, user:overrides) with key counts. "coverage": translated/missing/overridden counts per language against a source language. "overrides": one language\'s override file as raw YAML (lang required; namespace narrows it). "machine_translation": whether it is available, why not, and the per-request key limit. "import_status": what the legacy translation-strings plugin still holds awaiting import. [Requires: api.translations.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['languages', 'sources', 'coverage', 'overrides', 'machine_translation', 'import_status'], 'description' => 'Default: languages'],
                            'lang' => ['type' => 'string', 'description' => 'Required for overrides; for sources, the language to count (default: site default language)'],
                            'source_lang' => ['type' => 'string', 'description' => 'Coverage baseline language (default: site default language)'],
                            'namespace' => ['type' => 'string', 'description' => 'Overrides view: one namespace only, e.g. "PLUGIN_ADMIN"'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $lang = isset($args['lang']) ? (string) $args['lang'] : '';

                    return match ($args['view'] ?? 'languages') {
                        'languages' => ApiBridge::fromResponse($api->request('GET', '/i18n/languages')),
                        'sources' => ApiBridge::fromResponse($api->request('GET', '/i18n/sources', ApiBridge::pick($args, ['lang']))),
                        'coverage' => ApiBridge::fromResponse($api->request('GET', '/i18n/coverage', ApiBridge::pick($args, ['source_lang']))),
                        'overrides' => $lang === ''
                            ? ApiBridge::toolJson(['error' => 'lang is required for the overrides view'])
                            : ApiBridge::fromResponse($api->request(
                                'GET',
                                '/i18n/overrides/' . rawurlencode($lang),
                                ApiBridge::pick($args, ['namespace'])
                            )),
                        'machine_translation' => ApiBridge::fromResponse($api->request('GET', '/i18n/translate')),
                        'import_status' => ApiBridge::fromResponse($api->request('GET', '/i18n/import/translation-strings')),
                        default => ApiBridge::toolError('Invalid view. Must be one of: languages, sources, coverage, overrides, machine_translation, import_status'),
                    };
                },
            ],

            'search_translation_keys' => [
                'permission' => 'api.translations.read',
                'descriptor' => [
                    'name' => 'search_translation_keys',
                    'title' => 'Search Translation Keys',
                    'description' => 'Search interface-string keys across all providers, or fetch one exact key. status "missing" plus langs (e.g. ["fr"]) finds untranslated strings. provider ids come from get_translations view "sources". [Requires: api.translations.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'Exact dotted key (e.g. "PLUGIN_ADMIN.SAVE") to fetch alone, with its value in every language; letters, digits, underscore, dot and hyphen only. Ignores the other filters'],
                            'q' => ['type' => 'string', 'description' => 'Substring of the key name or its source-language value'],
                            'provider' => ['type' => 'string', 'description' => 'Exact provider id, e.g. "system:core", "plugin:admin", "theme:quark", "user:overrides"'],
                            'namespace' => ['type' => 'string', 'description' => 'First dot segment of the key, e.g. "PLUGIN_ADMIN"'],
                            'status' => ['type' => 'string', 'enum' => ['all', 'shipped', 'overridden', 'missing', 'unknown'], 'description' => 'State in the requested languages (default: all)'],
                            'langs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Language codes to report values for, e.g. ["fr", "de"]'],
                            'source_lang' => ['type' => 'string', 'description' => 'Language of the source_value column (default: site default language)'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Default 1'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Default 50'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $key = isset($args['key']) ? (string) $args['key'] : '';

                    if ($key !== '') {
                        return preg_match('/^[A-Za-z0-9_.\\-]+$/', $key) === 1
                            ? ApiBridge::fromResponse($api->request(
                                'GET',
                                '/i18n/keys/' . rawurlencode($key),
                                ApiBridge::pick($args, ['source_lang'])
                            ))
                            : ApiBridge::toolJson(['error' => 'key may only contain letters, digits, underscore, dot and hyphen']);
                    }

                    $langs = $args['langs'] ?? null;

                    return ApiBridge::fromResponse($api->request('GET', '/i18n/keys', [
                        // The MCP-facing 'langs' is an array; the endpoint reads a comma-separated string.
                        'langs' => is_array($langs) ? implode(',', $langs) : $langs,
                        'page' => $args['page'] ?? 1,
                        'per_page' => $args['per_page'] ?? 50,
                    ] + ApiBridge::pick($args, ['q', 'provider', 'namespace', 'status', 'source_lang'])));
                },
            ],

            'manage_translation_overrides' => [
                'permission' => 'api.translations.write',
                'descriptor' => [
                    'name' => 'manage_translation_overrides',
                    'title' => 'Manage Translation Overrides',
                    'description' => 'Edit one language\'s interface-string overrides (user/languages/<lang>.yaml), layered over the strings core, plugins and themes ship. Keys are flat dotted strings, e.g. "PLUGIN_ADMIN.SAVE". "set": write keys in set, delete keys in unset — never a null value (null is stored as ""); a value equal to the shipped string is not stored but treated as a removal and reported under "reverted". "replace": replace the whole file, or one namespace when namespace is given, with yaml; "" clears that scope. "import_legacy": merge the old translation-strings plugin\'s overrides in (plugin values win) and takes no other arguments; that plugin stays enabled and keeps outranking these files until you disable it. [Requires: api.translations.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['set', 'replace', 'import_legacy']],
                            'lang' => ['type' => 'string', 'description' => 'Language whose override file to edit; required for set and replace'],
                            'set' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Dotted key => string, e.g. {"PLUGIN_ADMIN.SAVE": "Enregistrer"}'],
                            'unset' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dotted keys to remove'],
                            'yaml' => ['type' => 'string', 'description' => 'YAML mapping; required for replace'],
                            'namespace' => ['type' => 'string', 'description' => 'For replace, e.g. "PLUGIN_ADMIN"'],
                            'source_lang' => ['type' => 'string', 'description' => 'Shapes the echoed result rows (set only)'],
                        ],
                        'required' => ['action'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                ],
                'handler' => static function (ApiBridge $api, array $args): array {
                    $lang = isset($args['lang']) ? (string) $args['lang'] : '';

                    return match ($args['action'] ?? null) {
                        'set' => $lang === ''
                            ? ApiBridge::toolJson(['error' => 'lang is required for the set action'])
                            : self::patchOverrides($api, $args, $lang),
                        'replace' => $lang === ''
                            ? ApiBridge::toolJson(['error' => 'lang is required for the replace action'])
                            : self::replaceOverrides($api, $args, $lang),
                        'import_legacy' => ApiBridge::fromResponse($api->request('POST', '/i18n/import/translation-strings')),
                        default => ApiBridge::toolError('Invalid action. Must be one of: set, replace, import_legacy'),
                    };
                },
            ],

            'machine_translate' => [
                'permission' => 'api.translations.write',
                'descriptor' => [
                    'name' => 'machine_translate',
                    'title' => 'Machine Translate',
                    'description' => 'Propose machine translations for interface-string keys. Writes nothing: review, then commit with manage_translation_overrides action "set". Needs the third-party ai-translate plugin installed, enabled and provider-configured — check get_translations view "machine_translation" first, or the call fails. ICU plural/select messages are never machine-translated; placeholders are protected. [Requires: api.translations.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'target_lang' => ['type' => 'string', 'description' => 'Language code to translate into'],
                            'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 200, 'description' => 'Dotted translation keys to translate, e.g. ["PLUGIN_ADMIN.SAVE"]'],
                            'source_lang' => ['type' => 'string', 'description' => 'Language to translate from (default: the site default language)'],
                        ],
                        'required' => ['target_lang', 'keys'],
                        'additionalProperties' => false,
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                'handler' => static fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse($api->request(
                    'POST',
                    '/i18n/translate',
                    [],
                    ApiBridge::pick($args, ['target_lang', 'keys', 'source_lang'])
                )),
            ],
        ];
    }

    private static function patchOverrides(ApiBridge $api, array $args, string $lang): array
    {
        $body = ApiBridge::pick($args, ['set', 'unset']);

        if (($body['set'] ?? []) === [] && ($body['unset'] ?? []) === []) {
            return ApiBridge::toolJson(['error' => 'Provide a non-empty set or unset for the set action.']);
        }

        return ApiBridge::fromResponse($api->request(
            'PATCH',
            '/i18n/overrides/' . rawurlencode($lang),
            ApiBridge::pick($args, ['source_lang']),
            $body
        ));
    }

    private static function replaceOverrides(ApiBridge $api, array $args, string $lang): array
    {
        // An empty yaml string is a legitimate "clear this scope", so presence is what matters.
        if (!array_key_exists('yaml', $args)) {
            return ApiBridge::toolJson(['error' => 'yaml is required for the replace action (pass an empty string to clear the scope)']);
        }

        return ApiBridge::fromResponse($api->request(
            'PUT',
            '/i18n/overrides/' . rawurlencode($lang),
            [],
            ApiBridge::pick($args, ['yaml', 'namespace'])
        ));
    }
}
