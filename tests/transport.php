<?php

declare(strict_types=1);

/**
 * Transport check: McpServer::handle() turns an HTTP request into the PSR-7
 * response Grav emits from its request pipeline — the path that arms
 * onShutdown, the same way a REST call through the api plugin does
 * (DECISIONS.md #1).
 *
 * Needs Grav's vendor/ for the PSR-7 classes, so like permission-gate.php it
 * reads the gitignored .gravtest/ tree and skips politely without it:
 *
 *   docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/transport.php
 *
 * There is no Grav container here, so authentication fails closed — which
 * yields exactly the two responses a client meets before it holds a key.
 */
$grav = __DIR__ . '/../.gravtest/grav-admin';
if (!is_file($grav . '/vendor/autoload.php')) {
    echo "transport: SKIP (no .gravtest/grav-admin)\n";
    exit(0);
}
require $grav . '/vendor/autoload.php';
foreach (['ApiBridge', 'PluginTools', 'Prompts', 'Resources', 'ToolRegistry', 'McpServer'] as $class) {
    require_once __DIR__ . '/../classes/' . $class . '.php';
}
foreach (glob(__DIR__ . '/../classes/Tools/*.php') as $toolFile) {
    require_once $toolFile;
}

use Grav\Plugin\McpServer\McpServer;
use Nyholm\Psr7\ServerRequest;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) {
        $failures++;
        echo "  FAIL {$what}\n";
    }
};

$server = new McpServer(null);

$r = $server->handle(new ServerRequest('GET', '/mcp'));
$body = json_decode((string) $r->getBody(), true);
$check($r->getStatusCode() === 405, 'GET is refused with 405');
$check($r->getHeaderLine('Allow') === 'POST', '405 carries Allow: POST');
$check($r->getHeaderLine('Content-Type') === 'application/json', 'error responses are JSON');
$check(($body['error']['code'] ?? null) === -32600, '405 body is a JSON-RPC invalid-request error');

$r = $server->handle(new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"ping"}'));
$body = json_decode((string) $r->getBody(), true);
$check($r->getStatusCode() === 401, 'an unauthenticated POST is refused with 401');
$check(str_starts_with($r->getHeaderLine('WWW-Authenticate'), 'Bearer realm="mcp"'), '401 carries WWW-Authenticate: Bearer realm="mcp"');
$check(($body['error']['code'] ?? null) === -32001 && array_key_exists('id', $body) && $body['id'] === null, '401 body is the JSON-RPC unauthorized error with a null id');

if ($failures > 0) {
    echo "transport: {$failures} failure(s)\n";
    exit(1);
}
echo "transport: OK\n";
