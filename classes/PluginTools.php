<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

use Grav\Common\Grav;

/**
 * Tools other Grav plugins publish through grav-plugin-api's /mcp/tools
 * endpoint (an `mcp.yaml` manifest or the onApiMcpTools event), turned into
 * MCP descriptors and handlers.
 *
 * The endpoint validates every entry and filters it to the caller's
 * permissions, so nothing is re-validated here — only our own scope cap, which
 * the api plugin cannot know about, still applies (see ToolRegistry::visible).
 *
 * Deliberately uncached: the response is per-caller and the fetch is one
 * in-process REST call, while a cached tool list outliving a config change (or
 * one caller's permissions leaking into another's listing) is a real bug.
 */
final class PluginTools
{
    /** Off without Grav (bare protocol tests) or when plugin_tools is disabled. */
    public static function enabled(?Grav $grav): bool
    {
        return $grav !== null && (bool) $grav['config']->get('plugins.mcp-server.plugin_tools', true);
    }

    /**
     * GET /mcp/tools. Every failure — plugin_tools off, no api plugin, non-200,
     * malformed body — degrades to null so tools/list still serves core tools.
     *
     * @return array{tools: list<array>, plugins: list<array>, warnings: list<string>}|null
     */
    public static function fetch(ApiBridge $api, ?Grav $grav): ?array
    {
        if (!self::enabled($grav)) {
            return null;
        }

        try {
            $resp = $api->request('GET', '/mcp/tools');
        } catch (\Throwable $e) {
            self::log('fetch failed: ' . $e->getMessage());

            return null;
        }

        $data = $resp['status'] < 400 ? ($resp['json']['data'] ?? null) : null;
        if (!is_array($data) || !is_array($data['tools'] ?? null)) {
            self::log(sprintf('no tool manifest served (status %d)', $resp['status']));

            return null;
        }

        $warnings = array_values(array_filter((array) ($data['warnings'] ?? []), 'is_string'));
        foreach ($warnings as $warning) {
            self::log($warning);
        }

        return [
            'tools' => array_values(array_filter($data['tools'], 'is_array')),
            'plugins' => array_values(array_filter((array) ($data['plugins'] ?? []), 'is_array')),
            'warnings' => $warnings,
        ];
    }

    /**
     * Registry entries for the fetched manifest. A name a core tool already
     * owns is skipped — the core descriptor is the one param-map keeps honest.
     *
     * @param array{tools: list<array>, plugins: list<array>, warnings: list<string>} $data
     * @param list<string> $coreNames
     * @return array<string, array{descriptor: array, permission: ?string, handler: callable}>
     */
    public static function tools(array $data, array $coreNames): array
    {
        $tools = [];
        foreach ($data['tools'] as $tool) {
            $name = $tool['name'] ?? null;
            if (!is_string($name) || !is_string($tool['method'] ?? null) || !is_string($tool['path'] ?? null)) {
                self::log('skipped an entry with no name, method or path');
                continue;
            }
            if (in_array($name, $coreNames, true)) {
                self::log(sprintf('%s: tool "%s" skipped: a core tool owns that name', $tool['plugin'] ?? '?', $name));
                continue;
            }

            $tools[$name] = [
                'permission' => is_string($tool['permission'] ?? null) ? $tool['permission'] : null,
                'descriptor' => self::descriptor($tool),
                'handler' => self::handler($tool),
            ];
        }

        return $tools;
    }

    /** @return array<string, mixed> MCP tool descriptor */
    private static function descriptor(array $tool): array
    {
        $permission = is_string($tool['permission'] ?? null) ? $tool['permission'] : null;

        $schema = is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : ['type' => 'object'];
        // json_decode gives [] for the endpoint's empty {} — encode it back as
        // an object, the way the core descriptors spell "no arguments".
        if (($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass();
        }

        $hints = [];
        foreach (['readOnly' => 'readOnlyHint', 'destructive' => 'destructiveHint', 'idempotent' => 'idempotentHint'] as $key => $hint) {
            if (isset($tool['annotations'][$key])) {
                $hints[$hint] = (bool) $tool['annotations'][$key];
            }
        }

        return array_filter([
            'name' => $tool['name'],
            'title' => is_string($tool['title'] ?? null) && $tool['title'] !== '' ? $tool['title'] : null,
            // "[Requires: ...]" matches the core tools' wording; the plugin
            // suffix says where an unfamiliar tool came from.
            'description' => (string) ($tool['description'] ?? '')
                . ($permission !== null ? ' [Requires: ' . $permission . ']' : '')
                . ' (plugin: ' . (string) ($tool['plugin'] ?? '?') . ')',
            'inputSchema' => $schema,
            'annotations' => $hints,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * The manifest call convention: path placeholders come from the arguments
     * (URL-encoded), GET sends everything left as query, the other methods send
     * the properties named in `query` as query and the rest as the JSON body.
     * Arguments the schema does not declare are dropped — unless the root
     * schema says `additionalProperties: true` (the manifest's free-form body,
     * e.g. fields a site's blueprint decides), in which case they ride along:
     * GET as query, the other methods into the body. When the manifest names a
     * `body` envelope property (manifest v2), that argument's value alone is
     * sent verbatim as the JSON body and everything else is path or query —
     * blueprint fields never share a namespace with them. Object/array values
     * routed to the query string are JSON-encoded first, or ApiBridge::request()
     * would cast them to the literal string "Array".
     */
    private static function handler(array $tool): \Closure
    {
        $method = strtoupper($tool['method']);
        $properties = $tool['input_schema']['properties'] ?? [];
        $declared = array_keys(is_array($properties) ? $properties : (array) $properties);
        $open = ($tool['input_schema']['additionalProperties'] ?? null) === true;
        $queryNames = array_values(array_filter((array) ($tool['query'] ?? []), 'is_string'));
        $pathParams = array_values(array_filter((array) ($tool['path_params'] ?? []), 'is_string'));
        $bodyName = is_string($tool['body'] ?? null) && $tool['body'] !== '' ? $tool['body'] : null;
        $bodyRequired = $bodyName !== null && in_array($bodyName, (array) ($tool['input_schema']['required'] ?? []), true);
        $template = $tool['path'];

        return static function (ApiBridge $api, array $args) use ($method, $declared, $open, $queryNames, $pathParams, $bodyName, $bodyRequired, $template): array {
            $path = $template;
            if (!$open) {
                $args = ApiBridge::pick($args, $declared);
            }

            foreach ($pathParams as $name) {
                if (!isset($args[$name]) || $args[$name] === '') {
                    return ApiBridge::toolError(sprintf('Missing required parameter: %s', $name));
                }
                $path = str_replace('{' . $name . '}', rawurlencode((string) $args[$name]), $path);
                unset($args[$name]);
            }

            // Throw rather than send "false": an argument that will not encode is
            // a caller error, reported before anything reaches the api.
            $jsonify = static fn(array $a): array => array_map(
                static fn($v) => is_array($v) ? json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $v,
                $a
            );

            $queryArgs = $method === 'GET' ? $args : ApiBridge::pick($args, $queryNames);
            try {
                $query = $jsonify($queryArgs);
            } catch (\JsonException $e) {
                return ApiBridge::toolError('A query argument could not be JSON-encoded: ' . $e->getMessage());
            }

            if ($method === 'GET') {
                return ApiBridge::fromResponse($api->request($method, $path, $query));
            }

            if ($bodyName !== null) {
                $envelope = $args[$bodyName] ?? null;
                if ($envelope === null && $bodyRequired) {
                    return ApiBridge::toolError(sprintf('Missing required parameter: %s', $bodyName));
                }
                // A JSON list decodes to a PHP array too; only a map (or {}) is an object.
                if ($envelope !== null && (!is_array($envelope) || ($envelope !== [] && array_is_list($envelope)))) {
                    return ApiBridge::toolError(sprintf('Parameter %s must be an object', $bodyName));
                }

                return ApiBridge::fromResponse($api->request($method, $path, $query, $envelope));
            }

            $body = array_diff_key($args, $queryArgs);

            return ApiBridge::fromResponse($api->request($method, $path, $query, $body === [] ? null : $body));
        };
    }

    /** Skips and upstream warnings to grav.log at debug — greppable, never fatal. */
    private static function log(string $message): void
    {
        $grav = class_exists(Grav::class) ? Grav::instance() : null;
        if ($grav !== null && isset($grav['log'])) {
            $grav['log']->debug('mcp-server plugin-tools: ' . $message);
        }
    }
}
