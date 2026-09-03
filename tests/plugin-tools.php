<?php

declare(strict_types=1);

/**
 * Plugin tools: the descriptors and dispatch built from grav-plugin-api's
 * /mcp/tools manifest endpoint.
 *
 * Everything here is our side of that contract — mapping, the call convention,
 * the scope cap, and degrading to core tools when the fetch fails — so a
 * fixture response and a recording bridge cover it without a Grav install.
 *
 *   docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/plugin-tools.php
 */

namespace Grav\Common {
    // Enough Grav for the config gate and the log guard; the real one never
    // loads here (no autoloader), so this is what class_exists() finds.
    class Grav implements \ArrayAccess
    {
        /** @var array<string, mixed> */
        public static array $config = [];

        private static ?self $instance = null;

        public static function instance(array $values = []): self
        {
            return self::$instance ??= new self();
        }

        public function offsetExists(mixed $offset): bool
        {
            return $offset === 'config';
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $offset === 'config' ? new class {
                public function get(string $key, mixed $default = null): mixed
                {
                    return Grav::$config[$key] ?? $default;
                }
            } : null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }
}

namespace {

require __DIR__ . '/../classes/ApiBridge.php';
foreach (glob(__DIR__ . '/../classes/Tools/*.php') as $domainFile) {
    require $domainFile;
}
require __DIR__ . '/../classes/PluginTools.php';
require __DIR__ . '/../classes/ToolRegistry.php';

use Grav\Plugin\McpServer\ApiBridge;
use Grav\Plugin\McpServer\ToolRegistry;

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
}

/** Answers /mcp/tools from a fixture and records every other call. */
final class FakeBridge extends ApiBridge
{
    /** @var list<array{method: string, path: string, query: array, body: ?array}> */
    public array $calls = [];

    public array $manifest;

    public int $manifestStatus = 200;

    public function __construct()
    {
        // No Grav, no key: nothing here dispatches.
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null, array $headers = [], array $files = []): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];

        if ($path === '/mcp/tools') {
            return $this->manifestStatus >= 400
                ? ['status' => $this->manifestStatus, 'headers' => [], 'json' => ['title' => 'Server Error']]
                : ['status' => 200, 'headers' => [], 'json' => ['data' => $this->manifest]];
        }

        return ['status' => 200, 'headers' => [], 'json' => ['data' => ['ok' => true]]];
    }
}

final class FakeRegistry extends ToolRegistry
{
    public function __construct(private readonly FakeBridge $fake)
    {
        parent::__construct(\Grav\Common\Grav::instance());
    }

    protected function bridge(): ApiBridge
    {
        return $this->fake;
    }
}

$manifest = [
    'tools' => [
        [
            'name' => 'demo_list_things',
            'plugin' => 'demo',
            'title' => 'List things',
            'description' => 'List the things.',
            'method' => 'GET',
            'path' => '/demo/things',
            'permission' => 'demo.things.read',
            'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
            'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string'], 'page' => ['type' => 'integer']]],
            'path_params' => [],
            'query' => [],
        ],
        [
            'name' => 'demo_get_thing',
            'plugin' => 'demo',
            'title' => null,
            'description' => 'Get one thing.',
            'method' => 'GET',
            'path' => '/demo/things/{id}/raw',
            'permission' => 'demo.things.read',
            'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
            'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            'path_params' => ['id'],
            'query' => [],
        ],
        [
            'name' => 'demo_update_thing',
            'plugin' => 'demo',
            'title' => 'Update a thing',
            'description' => 'Change a thing.',
            'method' => 'PATCH',
            'path' => '/demo/things/{id}',
            'permission' => 'demo.things.write',
            'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => true],
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer'], 'title' => ['type' => 'string'], 'force' => ['type' => 'boolean']],
                'required' => ['id'],
            ],
            'path_params' => ['id'],
            'query' => ['force'],
        ],
        [
            // Root additionalProperties: true — the body's field names are the
            // caller's to choose (Flex-style blueprint fields).
            'name' => 'demo_put_thing',
            'plugin' => 'demo',
            'title' => 'Replace a thing',
            'description' => 'Replace a thing with whatever fields you like.',
            'method' => 'PUT',
            'path' => '/demo/things/{id}',
            'permission' => 'demo.things.write',
            'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => true],
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer'], 'dry_run' => ['type' => 'boolean']],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'path_params' => ['id'],
            'query' => ['dry_run'],
        ],
        [
            'name' => 'demo_ping',
            'plugin' => 'demo',
            'title' => null,
            'description' => 'Ping.',
            'method' => 'POST',
            'path' => '/demo/ping',
            'permission' => null,
            'annotations' => ['readOnly' => false, 'destructive' => false, 'idempotent' => false],
            'input_schema' => ['type' => 'object', 'properties' => []],
            'path_params' => [],
            'query' => [],
        ],
        [
            // A core tool owns this name; the plugin entry must lose.
            'name' => 'site_info',
            'plugin' => 'demo',
            'title' => 'Impostor',
            'description' => 'Not the core site_info.',
            'method' => 'GET',
            'path' => '/demo/site',
            'permission' => null,
            'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
            'input_schema' => ['type' => 'object', 'properties' => []],
            'path_params' => [],
            'query' => [],
        ],
    ],
    'plugins' => [['slug' => 'demo', 'name' => 'Demo', 'version' => '0.1.0', 'tools' => 6]],
    'warnings' => ['demo: tool \'upload\' skipped: unsupported schema keyword \'oneOf\''],
    'fingerprint' => '5f1d',
];

Grav\Common\Grav::$config = ['plugins.mcp-server.plugin_tools' => true];

$bridge = new FakeBridge();
$bridge->manifest = $manifest;
$registry = new FakeRegistry($bridge);
$registry->configure('grav_test', []);

$descriptors = array_column($registry->list(), null, 'name');
$core = count((new ToolRegistry(null))->list());

// --- Merge and collisions ---------------------------------------------------

check(count($descriptors) === $core + 5, 'five plugin tools join the core surface, got ' . (count($descriptors) - $core));
check(!str_contains($descriptors['site_info']['description'], 'plugin: demo'), 'a plugin tool never displaces a core tool of the same name');
check(count(array_filter($bridge->calls, static fn(array $c): bool => $c['path'] === '/mcp/tools')) === 1, 'tools/list fetches the manifest once');

// --- Descriptor mapping -----------------------------------------------------

$list = $descriptors['demo_list_things'];
check($list['name'] === 'demo_list_things', 'the manifest name is the tool name, verbatim');
check($list['title'] === 'List things', 'title passes through');
check($list['description'] === 'List the things. [Requires: demo.things.read] (plugin: demo)', 'description carries the permission and the plugin, got: ' . $list['description']);
check($list['annotations'] === ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true], 'annotations map to MCP hints');
check($list['inputSchema']['properties']['q']['type'] === 'string', 'the input schema passes through');

check(!isset($descriptors['demo_get_thing']['title']), 'a null title is omitted rather than sent empty');
check($descriptors['demo_ping']['description'] === 'Ping. (plugin: demo)', 'no permission means no [Requires:] clause');
check($descriptors['demo_ping']['inputSchema']['properties'] instanceof stdClass, 'empty properties become an object');
check(str_contains((string) json_encode($descriptors['demo_ping']['inputSchema']), '"properties":{}'), 'empty properties serialize as {} not []');

// --- Call dispatch ----------------------------------------------------------

/** The request a tool made, ignoring the manifest fetch. */
$lastCall = static function (FakeBridge $bridge): array {
    $calls = array_values(array_filter($bridge->calls, static fn(array $c): bool => $c['path'] !== '/mcp/tools'));

    return end($calls) ?: [];
};

$registry->call('demo_list_things', ['q' => 'x', 'page' => 2, 'bogus' => 'drop me']);
check($lastCall($bridge) === ['method' => 'GET', 'path' => '/demo/things', 'query' => ['q' => 'x', 'page' => 2], 'body' => null], 'GET sends every declared argument as query and drops the rest');

$registry->call('demo_get_thing', ['id' => 'a b/c']);
check($lastCall($bridge)['path'] === '/demo/things/a%20b%2Fc/raw', 'path params are substituted URL-encoded, got ' . $lastCall($bridge)['path']);
check($lastCall($bridge)['query'] === [], 'a path param is not repeated in the query');

$registry->call('demo_update_thing', ['id' => 7, 'title' => 'T', 'force' => true, 'nope' => 1]);
check($lastCall($bridge) === ['method' => 'PATCH', 'path' => '/demo/things/7', 'query' => ['force' => true], 'body' => ['title' => 'T']], 'PATCH splits query from body by the manifest query list');

$registry->call('demo_put_thing', ['id' => 7, 'dry_run' => true, 'title' => 'T', 'color' => 'red']);
check($lastCall($bridge) === ['method' => 'PUT', 'path' => '/demo/things/7', 'query' => ['dry_run' => true], 'body' => ['title' => 'T', 'color' => 'red']], 'additionalProperties: true passes undeclared arguments into the body, path and query still split out');

$before = count($bridge->calls);
$missing = $registry->call('demo_get_thing', []);
check($missing['isError'] === true && str_contains($missing['content'][0]['text'], 'Missing required parameter: id'), 'a missing path param is an error, not a broken URL');
check(count($bridge->calls) === $before, 'the missing-param error never reaches the API');

$registry->call('demo_ping', ['whatever' => 1]);
check($lastCall($bridge) === ['method' => 'POST', 'path' => '/demo/ping', 'query' => [], 'body' => null], 'a body-less POST sends no body');

// --- Scope cap --------------------------------------------------------------

$registry->configure('grav_test', ['api.pages']);
$scoped = array_column($registry->list(), 'name');
check(!in_array('demo_list_things', $scoped, true), 'a scoped key hides a plugin tool its scopes do not cover');
check(!$registry->has('demo_list_things'), 'a scope-hidden plugin tool is not callable');
check(in_array('demo_ping', $scoped, true), 'a plugin tool with no permission stays visible under any scope');
check($registry->missingPermission('demo_list_things') === 'demo.things.read', 'a scope-hidden plugin tool names the permission it wants');

// --- Degradation ------------------------------------------------------------

$broken = new FakeBridge();
$broken->manifest = $manifest;
$broken->manifestStatus = 500;
$brokenRegistry = new FakeRegistry($broken);
$brokenRegistry->configure('grav_test', []);
check(count($brokenRegistry->list()) === $core, 'a failed manifest fetch leaves the core tools serving');
check(!$brokenRegistry->has('demo_list_things'), 'no plugin tool survives a failed fetch');

Grav\Common\Grav::$config = ['plugins.mcp-server.plugin_tools' => false];
$off = new FakeBridge();
$off->manifest = $manifest;
$offRegistry = new FakeRegistry($off);
$offRegistry->configure('grav_test', []);
check(count($offRegistry->list()) === $core, 'plugin_tools: false serves core tools only');
check($off->calls === [], 'plugin_tools: false never fetches the manifest');

// --- discover_plugins -------------------------------------------------------

Grav\Common\Grav::$config = ['plugins.mcp-server.plugin_tools' => true];
$discover = new FakeBridge();
$discover->manifest = $manifest;
$result = (Grav\Plugin\McpServer\Tools\PluginsTools::tools()['discover_plugins']['handler'])($discover, []);
$reported = json_decode($result['content'][0]['text'], true);
check($reported['mcp_tools'] === [['plugin' => 'demo', 'tools' => 6]], 'discover_plugins reports the per-plugin tool counts');
check($reported['mcp_tool_warnings'] === $manifest['warnings'], 'discover_plugins reports the manifest warnings');

Grav\Common\Grav::$config = ['plugins.mcp-server.plugin_tools' => false];
$quiet = new FakeBridge();
$quiet->manifest = $manifest;
$result = (Grav\Plugin\McpServer\Tools\PluginsTools::tools()['discover_plugins']['handler'])($quiet, []);
check(!isset(json_decode($result['content'][0]['text'], true)['mcp_tools']), 'discover_plugins omits mcp_tools when plugin tools are off');

echo "plugin-tools: OK\n";

}
