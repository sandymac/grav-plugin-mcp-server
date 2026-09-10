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
                    'description' => 'Overview of the site\'s interface translation strings (language files), not page content. Views: "languages" lists every configured language and which ones have local overrides; "sources" lists the providers that ship strings for a language (system:core, plugin:<slug>, theme:<slug>, user:overrides) with key counts and namespaces; "coverage" reports total/translated/missing/overridden counts per language against a source language; "overrides" returns the raw YAML of one language\'s override file (lang is required, optionally narrowed to one namespace); "machine_translation" probes whether machine translation is available and why not, plus the per-request key limit; "import_status" reports what the legacy translation-strings plugin still holds waiting to be imported. [Requires: api.translations.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'view' => ['type' => 'string', 'enum' => ['languages', 'sources', 'coverage', 'overrides', 'machine_translation', 'import_status'], 'description' => 'What to retrieve (default: languages)'],
                            'lang' => ['type' => 'string', 'description' => 'Language code. Required for the overrides view (which file to show); for the sources view, which language to count keys for (default: the site default language)'],
                            'source_lang' => ['type' => 'string', 'description' => 'Language to measure coverage against (coverage view only; default: the site default language)'],
                            'namespace' => ['type' => 'string', 'description' => 'Limit the returned YAML to one namespace, e.g. "PLUGIN_ADMIN" (overrides view only)'],
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
                    'description' => 'Search interface translation keys across every provider, or fetch one key by name. Each row gives the key, its namespace, the source-language value, which providers ship it, its owner, and the value and state in each requested language. To find untranslated strings, pass status "missing" with langs ["fr"]. Filters: q matches a substring of the key or its source value; provider is an exact provider id from get_translations view "sources" (e.g. "system:core", "plugin:admin", "user:overrides"); namespace is the first dot segment of a key (e.g. "PLUGIN_ADMIN" for PLUGIN_ADMIN.SAVE). Results are paginated, at most 500 per page. [Requires: api.translations.read]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'description' => 'Exact dotted key (e.g. "PLUGIN_ADMIN.SAVE") to fetch on its own, with its value in every language; letters, digits, underscore, dot and hyphen only. Ignores the other filters'],
                            'q' => ['type' => 'string', 'description' => 'Substring match over the key name or its source-language value'],
                            'provider' => ['type' => 'string', 'description' => 'Exact provider id, e.g. "system:core", "plugin:admin", "theme:quark", "user:overrides"'],
                            'namespace' => ['type' => 'string', 'description' => 'First dot segment of the key, e.g. "PLUGIN_ADMIN"'],
                            'status' => ['type' => 'string', 'enum' => ['all', 'shipped', 'overridden', 'missing', 'unknown'], 'description' => 'Filter by translation state in the requested languages (default: all)'],
                            'langs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Language codes to report values for, e.g. ["fr", "de"]'],
                            'source_lang' => ['type' => 'string', 'description' => 'Language the source_value column comes from (default: the site default language)'],
                            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (default: 1)'],
                            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Rows per page (default: 50, max: 500)'],
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
                    'description' => 'Edit a language\'s interface-string overrides, stored in user/languages/<lang>.yaml and applied on top of whatever core, plugins and themes ship. Keys are flat dotted strings, e.g. "PLUGIN_ADMIN.SAVE". Action "set" patches individual keys: pass set for values to write and unset for keys to delete — deleting is unset, never a null value (null is stored as an empty string). A value equal to the shipped translation is not stored; it is treated as a removal and reported under "reverted". Action "replace" swaps the whole override file for the YAML you provide, or only one namespace when namespace is given; an empty yaml string clears that scope. Action "import_legacy" merges the old translation-strings plugin\'s configured overrides into these files (plugin values win on conflict) and takes no other arguments — it leaves that plugin enabled, so disable it afterwards or it keeps outranking these files. Both write actions flush Grav\'s compiled language cache. [Requires: api.translations.write]',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['set', 'replace', 'import_legacy'], 'description' => 'Action to perform'],
                            'lang' => ['type' => 'string', 'description' => 'Language code whose override file to edit (required for set and replace)'],
                            'set' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Dotted key => translated string, e.g. {"PLUGIN_ADMIN.SAVE": "Enregistrer"} (set action)'],
                            'unset' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Dotted keys to remove from the override file (set action)'],
                            'yaml' => ['type' => 'string', 'description' => 'Replacement YAML mapping for the file, or for one namespace when namespace is given; empty string clears that scope (required for replace)'],
                            'namespace' => ['type' => 'string', 'description' => 'Limit a replace to one namespace, e.g. "PLUGIN_ADMIN" (replace action only)'],
                            'source_lang' => ['type' => 'string', 'description' => 'Language used to shape the echoed result rows (set action only)'],
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
                    'description' => 'Propose machine translations for interface-string keys. Runs synchronously and returns one proposal per key with ok and a reason when it failed. It writes nothing — review the proposals, then commit the ones you want with manage_translation_overrides action "set". Requires the third-party ai-translate plugin installed, enabled and provider-configured; check get_translations view "machine_translation" first, otherwise the call fails with an explanation of what is missing. ICU plural/select messages are never machine-translated; placeholders in a string are protected. At most 200 keys per call. [Requires: api.translations.write]',
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
