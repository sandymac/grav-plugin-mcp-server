<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

/**
 * Prompt templates: pure text with argument interpolation.
 * No API calls, no Grav needed — static data + string rendering.
 */
class Prompts
{
    /** @var array<string, array{title:string, description:string, arguments:list<array{name:string, description:string, required:bool}>}> */
    private const array META = [
        'create_blog_post' => [
            'title' => 'Create Blog Post',
            'description' => 'Guided workflow for creating a new blog post with proper template, frontmatter, taxonomy, and media.',
            'arguments' => [
                ['name' => 'topic', 'description' => 'Blog post topic or title', 'required' => true],
                ['name' => 'lang', 'description' => 'Language code (for multilingual sites)', 'required' => false],
                ['name' => 'template', 'description' => 'Page template to use (default: "item" or "blog")', 'required' => false],
            ],
        ],
        'translate_page' => [
            'title' => 'Translate Page',
            'description' => 'Translate a page to a target language, preserving structure and adapting content appropriately.',
            'arguments' => [
                ['name' => 'route', 'description' => 'Page route to translate', 'required' => true],
                ['name' => 'target_lang', 'description' => 'Target language code (e.g. "fr", "de", "es")', 'required' => true],
            ],
        ],
        'translate_ui_strings' => [
            'title' => 'Translate Interface Strings',
            'description' => 'Fill a language\'s missing interface strings (language-file translations, not page content): find the gaps, propose machine translations where available, review, and commit them as overrides.',
            'arguments' => [
                ['name' => 'target_lang', 'description' => 'Language code to translate into (e.g. "fr", "de", "es")', 'required' => true],
                ['name' => 'namespace', 'description' => 'Limit to one namespace, e.g. "PLUGIN_ADMIN" (first dot segment of the keys)', 'required' => false],
            ],
        ],
        'site_health_check' => [
            'title' => 'Site Health Check',
            'description' => 'Comprehensive site health check: updates, reports, logs, backups, and scheduler status.',
            'arguments' => [],
        ],
        'content_audit' => [
            'title' => 'Content Audit',
            'description' => 'Audit site content for missing metadata, unpublished drafts, and content quality issues.',
            'arguments' => [
                ['name' => 'scope', 'description' => 'Scope of audit', 'required' => false],
            ],
        ],
        'plugin_setup' => [
            'title' => 'Plugin Setup',
            'description' => 'Search for, install, and configure a Grav plugin end-to-end.',
            'arguments' => [
                ['name' => 'search_query', 'description' => 'What kind of plugin you need', 'required' => true],
            ],
        ],
        'bulk_update' => [
            'title' => 'Bulk Update Frontmatter',
            'description' => 'Update a specific frontmatter field across multiple pages matching a filter.',
            'arguments' => [
                ['name' => 'field', 'description' => 'Header field to update (e.g. "taxonomy.category", "metadata.author")', 'required' => true],
                ['name' => 'value', 'description' => 'New value to set', 'required' => true],
                ['name' => 'filter', 'description' => 'Page filter: template name, parent route, or search query', 'required' => false],
            ],
        ],
    ];

    /** @return list<array<string, mixed>> MCP prompt descriptors */
    public static function list(): array
    {
        $descriptors = [];
        foreach (self::META as $name => $m) {
            $descriptors[] = [
                'name' => $name,
                'title' => $m['title'],
                'description' => $m['description'],
                'arguments' => $m['arguments'],
            ];
        }

        return $descriptors;
    }

    /** @return array{description:string, messages:list<array>}|null null for an unknown prompt */
    public static function get(string $name, array $args): ?array
    {
        $meta = self::META[$name] ?? null;
        if ($meta === null) {
            return null;
        }

        $text = match ($name) {
            'create_blog_post' => self::createBlogPost($args),
            'translate_page' => self::translatePage($args),
            'translate_ui_strings' => self::translateUiStrings($args),
            'site_health_check' => self::siteHealthCheck(),
            'content_audit' => self::contentAudit($args),
            'plugin_setup' => self::pluginSetup($args),
            'bulk_update' => self::bulkUpdate($args),
            default => null, // a META key without a match arm is a bug; fail as unknown prompt
        };
        if ($text === null) {
            return null;
        }

        return [
            'description' => $meta['description'],
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    private static function createBlogPost(array $a): string
    {
        $topic = (string) ($a['topic'] ?? '');
        $template = (string) ($a['template'] ?? '') !== '' ? sprintf(' (use "%s")', $a['template']) : '';
        $lang = (string) ($a['lang'] ?? '') !== '' ? "\n   - Language: {$a['lang']}" : '';

        return <<<TXT
Create a blog post about: {$topic}

Steps:
1. Use list_page_templates to find the right blog template (usually "item" or "blog")
2. Use get_taxonomy to see existing categories and tags
3. Use list_pages with parent="/blog" to understand the blog structure
4. Use create_page with:
   - An SEO-friendly slug derived from the topic
   - The appropriate template{$template}
   - Well-structured markdown content
   - Relevant taxonomy tags and categories from existing values
   - Published: true{$lang}
5. Confirm the page was created successfully with get_page
TXT;
    }

    private static function translatePage(array $a): string
    {
        $route = (string) ($a['route'] ?? '');
        $targetLang = (string) ($a['target_lang'] ?? '');

        return <<<TXT
Translate the page at "{$route}" to {$targetLang}.

Steps:
1. Use get_page to read the current page content and frontmatter
2. Use get_page_translations to see which translations already exist
3. Use list_languages to confirm {$targetLang} is a configured language
4. Translate the title, content, and relevant frontmatter fields
5. Use manage_page_translation with action="create" and the translated content
6. Use get_page_translations with source_lang and target_lang to verify the translation covers all content
TXT;
    }

    private static function translateUiStrings(array $a): string
    {
        $targetLang = (string) ($a['target_lang'] ?? '');
        $namespace = (string) ($a['namespace'] ?? '');
        $nsSuffix = $namespace !== '' ? " (namespace \"{$namespace}\")" : '';
        $nsFilter = $namespace !== '' ? ", namespace=\"{$namespace}\"" : '';

        return <<<TXT
Translate the site's interface strings (language files, not page content) into {$targetLang}{$nsSuffix}.

Steps:
1. Use get_translations with view="machine_translation" to see whether machine translation is available (it needs the ai-translate plugin installed, enabled and configured). If it is not, write the translations yourself in step 4.
2. Use get_translations with view="coverage" to see how many {$targetLang} strings are missing
3. Use search_translation_keys with status="missing", langs=["{$targetLang}"]{$nsFilter}, per_page=200 to list the untranslated keys, paging until done
4. For each page of keys, use machine_translate with target_lang="{$targetLang}" and keys=[...] (max 200 per call). It writes nothing — it returns one proposal per key. Review each: keep the ones with ok=true, and translate by hand those whose reason is no_source, icu_needs_human or placeholders_mangled (keep every {placeholder} and ICU plural form intact)
5. Use manage_translation_overrides with action="set", lang="{$targetLang}" and set={key: value} to commit the reviewed translations. Keys are flat dotted strings like "PLUGIN_ADMIN.SAVE"; a value equal to the shipped translation is dropped and reported as reverted
6. Use get_translations with view="coverage" again to confirm the missing count dropped

Report: how many strings were translated, which were left for a human and why, and anything that looked wrong in the source text.
TXT;
    }

    private static function siteHealthCheck(): string
    {
        return <<<TXT
Perform a comprehensive health check on this Grav site.

Steps:
1. Use get_system_info to check Grav and PHP versions
2. Use get_packages with view="updates" to find available updates for core, plugins, and themes
3. Use run_reports to run security and YAML lint checks
4. Use get_logs with level="ERROR" to check for recent errors
5. Use manage_backups with action="list" to verify backup recency
6. Use get_scheduler with view="status" to check cron is configured
7. Use get_dashboard with view="notifications" for any important system notices

Summarize findings as:
- Critical issues (security, errors)
- Updates available
- Backup status
- Scheduler status
- Recommendations
TXT;
    }

    private static function contentAudit(array $a): string
    {
        $scope = $a['scope'] ?? null;
        $scopeSuffix = $scope !== null && $scope !== '' ? " (scope: {$scope})" : '';
        $publishedFilter = match ($scope) {
            'drafts' => ' with published=false',
            'published' => ' with published=true',
            default => '',
        };

        return <<<TXT
Audit the site content{$scopeSuffix}.

Steps:
1. Use list_pages to get all pages{$publishedFilter}
2. For each page, use get_page to check:
   - Missing or empty title
   - Missing or very short content
   - Missing taxonomy (categories, tags)
   - Missing date
   - Unpublished status (if scope includes drafts)
3. Use get_taxonomy to check for unused or orphaned taxonomy values
4. Check for pages with no media vs pages that could benefit from images

Summarize findings as:
- Pages missing metadata (list routes)
- Unpublished drafts (if applicable)
- Content quality issues
- Taxonomy health
- Recommendations
TXT;
    }

    private static function pluginSetup(array $a): string
    {
        $searchQuery = (string) ($a['search_query'] ?? '');

        return <<<TXT
Find, install, and configure a plugin for: {$searchQuery}

Steps:
1. Use search_packages to find relevant plugins
2. For promising results, use get_packages with view="info" and include="readme" to review docs
3. Use manage_packages with action="install" to install the chosen plugin
4. Use get_blueprint with type="plugin" and the plugin slug to see all config options
5. Use get_config with scope="plugins/{slug}" to see default config
6. Use update_config to set appropriate configuration values
7. Use clear_cache to ensure changes take effect
8. Verify with get_config that the settings were saved correctly
TXT;
    }

    private static function bulkUpdate(array $a): string
    {
        $field = (string) ($a['field'] ?? '');
        $value = (string) ($a['value'] ?? '');
        $filter = $a['filter'] ?? null;
        $filterSuffix = $filter !== null && $filter !== '' ? " matching: {$filter}" : '';
        $filterHint = $filter !== null && $filter !== '' ? " with appropriate filter for \"{$filter}\"" : '';

        return <<<TXT
Bulk update the "{$field}" field to "{$value}" across pages{$filterSuffix}.

Steps:
1. Use list_pages{$filterHint} to identify target pages
2. For each page:
   a. Use get_page to read current content and get the ETag
   b. Use update_page with the header field set and the ETag for safe update
3. Report results: how many pages updated, any errors

Important:
- Always use ETags to prevent overwriting concurrent changes
- Log which pages were updated and which failed
- If any update fails with 409 (conflict), re-fetch and retry
TXT;
    }
}
