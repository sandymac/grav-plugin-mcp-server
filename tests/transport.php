<?php

declare(strict_types=1);

/**
 * Transport check: the MCP endpoint answers from Grav's request pipeline
 * (DECISIONS.md #1). Drives the real entry point — McpServerPlugin::
 * onRequestHandlerInit with a RequestHandlerEvent — against a bare Grav
 * container, and asserts the pieces the live test caught or that CI could
 * otherwise never see: pages disabled before the api plugin gets to build
 * the index lazily, the object cache forced on, the response set on the
 * event, and handle()'s parsed-body / raw-stream / notification / batch
 * paths through to real PSR-7 responses.
 *
 * Needs Grav's vendor/ for the PSR-7 and core classes, so like
 * permission-gate.php it reads the gitignored .gravtest/ tree and skips
 * politely without it:
 *
 *   docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/transport.php
 *
 * Not covered here: the planted-session-cookie strip — headers_list() is
 * always empty under the CLI SAPI, so only the live test (no Set-Cookie on
 * an MCP response) can see it.
 */
$gravRoot = __DIR__ . '/../.gravtest/grav-admin';
if (!is_file($gravRoot . '/vendor/autoload.php')) {
    echo "transport: SKIP (no .gravtest/grav-admin)\n";
    exit(0);
}
require $gravRoot . '/vendor/autoload.php';
require_once __DIR__ . '/../mcp-server.php';
foreach (['ApiBridge', 'PluginTools', 'Prompts', 'Resources', 'ToolRegistry', 'McpServer'] as $class) {
    require_once __DIR__ . '/../classes/' . $class . '.php';
}
foreach (glob(__DIR__ . '/../classes/Tools/*.php') as $toolFile) {
    require_once $toolFile;
}

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Processors\Events\RequestHandlerEvent;
use Grav\Plugin\McpServer\McpServer;
use Grav\Plugin\McpServerPlugin;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) {
        $failures++;
        echo "  FAIL {$what}\n";
    }
};
$json = static fn(ResponseInterface $r): ?array => json_decode((string) $r->getBody(), true);

// --- Fail-closed responses need no Grav at all --------------------------------

$bare = new McpServer(null);

$r = $bare->handle(new ServerRequest('GET', '/mcp'));
$check($r->getStatusCode() === 405, 'GET is refused with 405');
$check($r->getHeaderLine('Allow') === 'POST', '405 carries Allow: POST');
$check($r->getHeaderLine('Content-Type') === 'application/json', 'error responses are JSON');
$check(($json($r)['error']['code'] ?? null) === -32600, '405 body is a JSON-RPC invalid-request error');

$r = $bare->handle(new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"ping"}'));
$check($r->getStatusCode() === 401, 'an unauthenticated POST is refused with 401');
$check(str_starts_with($r->getHeaderLine('WWW-Authenticate'), 'Bearer realm="mcp"'), '401 carries WWW-Authenticate: Bearer realm="mcp"');
$body = $json($r);
$check(($body['error']['code'] ?? null) === -32001 && array_key_exists('id', $body) && $body['id'] === null, '401 body is the JSON-RPC unauthorized error with a null id');

// --- The pipeline entry point, against a bare container --------------------------
//
// require_auth off is the one configuration that lets handle() past auth
// without the api plugin, which is what lets this reach body parsing and
// dispatch. The pages and cache doubles record the request setup the plugin
// must apply before handing off (the live-test regression).

$grav = Grav::instance();
$grav['config'] = new Config([
    'plugins' => [
        'mcp-server' => ['require_auth' => false, 'plugin_tools' => false],
        'api' => ['force_cache' => true],
    ],
]);
$pages = new class {
    public bool $disabled = false;

    public function disablePages(): void
    {
        $this->disabled = true;
    }
};
$cache = new class {
    public ?bool $enabled = null;

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
};
$grav['pages'] = $pages;
$grav['cache'] = $cache;
// The container defines a session service; resolving it would need a full
// boot, and the cookie strip is out of scope here (see header).
unset($grav['session']);

$plugin = new McpServerPlugin('mcp-server', $grav, $grav['config']);

$drive = static function (ServerRequest $request) use ($plugin): RequestHandlerEvent {
    $event = new RequestHandlerEvent(['request' => $request]);
    $plugin->onRequestHandlerInit($event);

    return $event;
};

// RequestProcessor decodes an application/json body into the parsed body
// before the event fires; mirror that.
$ping = '{"jsonrpc":"2.0","id":7,"method":"ping"}';
$event = $drive((new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], $ping))->withParsedBody(json_decode($ping, true)));
$r = $event->getResponse();
$check($r instanceof ResponseInterface, 'onRequestHandlerInit sets a response on the event');
$check($pages->disabled === true, 'pages are disabled before handling, so the api plugin\'s enablePages() builds the index');
$check($cache->enabled === true, 'the object cache is forced on per plugins.api.force_cache');
$check($r !== null && $r->getStatusCode() === 200, 'a request reaches dispatch and answers 200');
$check($r !== null && $r->getHeaderLine('Content-Type') === 'application/json', '200 is JSON');
$body = $r !== null ? $json($r) : null;
$check(($body['id'] ?? null) === 7 && array_key_exists('result', (array) $body), 'ping answers a JSON-RPC result with the request id (parsed body path)');

// A client that omits the content type gets no parsed body; the raw stream is read instead.
$r = $drive(new ServerRequest('POST', '/mcp', [], $ping))->getResponse();
$check($r !== null && $r->getStatusCode() === 200 && ($json($r)['id'] ?? null) === 7, 'a body with no content type is read from the raw stream');

// A notification is acknowledged with 202 and no body.
$note = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
$r = $drive((new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], $note))->withParsedBody(json_decode($note, true)))->getResponse();
$check($r !== null && $r->getStatusCode() === 202, 'a notification is acknowledged with 202');
$check($r !== null && (string) $r->getBody() === '' && !$r->hasHeader('Content-Type'), '202 has no body and no content type');

// Batching is refused before dispatch.
$batch = '[' . $ping . ']';
$r = $drive((new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], $batch))->withParsedBody(json_decode($batch, true)))->getResponse();
$check($r !== null && $r->getStatusCode() === 400 && ($json($r)['error']['code'] ?? null) === -32700, 'a JSON-RPC batch is refused with 400 / -32700');

if ($failures > 0) {
    echo "transport: {$failures} failure(s)\n";
    exit(1);
}
echo "transport: OK\n";
