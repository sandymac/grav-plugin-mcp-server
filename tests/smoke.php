<?php

declare(strict_types=1);

/**
 * Smallest check that fails if the JSON-RPC dispatch breaks.
 * Run: php tests/smoke.php   (no Grav install needed)
 */

require __DIR__ . '/../classes/ApiBridge.php';
foreach (glob(__DIR__ . '/../classes/Tools/*.php') as $domainFile) {
    require $domainFile;
}
require __DIR__ . '/../classes/ToolRegistry.php';
require __DIR__ . '/../classes/Resources.php';
require __DIR__ . '/../classes/Prompts.php';
require __DIR__ . '/../classes/McpServer.php';
require __DIR__ . '/../classes/OAuth/OAuthStore.php';
require __DIR__ . '/../classes/OAuth/OAuthServer.php';

use Grav\Plugin\McpServer\McpServer;
use Grav\Plugin\McpServer\OAuth\OAuthServer;
use Grav\Plugin\McpServer\OAuth\OAuthStore;
use Grav\Plugin\McpServer\ToolRegistry;

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
}

$server = new McpServer();

$init = $server->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
check($init['result']['protocolVersion'] === '2025-06-18', 'initialize echoes supported protocol version');
check($init['result']['serverInfo']['name'] === 'grav-plugin-mcp-server', 'initialize returns serverInfo');
check(str_contains((string) ($init['result']['instructions'] ?? ''), 'whoami'), 'initialize instructions point at whoami');
check($init['result']['capabilities']['resources'] === ['subscribe' => false, 'listChanged' => false], 'initialize declares resources capability');
check($init['result']['capabilities']['prompts'] === ['listChanged' => false], 'initialize declares prompts capability');

$old = $server->dispatch(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]);
check($old['result']['protocolVersion'] === '2025-06-18', 'unsupported protocol version falls back to latest');

$tools = $server->dispatch(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
$descriptors = $tools['result']['tools'];
$byName = array_column($descriptors, null, 'name');
check($descriptors[0]['name'] === 'site_info', 'tools/list returns site_info first');
check(count($descriptors) === 50, 'tools/list returns all 50 tools, got ' . count($descriptors));
check(
    array_diff(
        [
            // one representative per domain, plus the full pages set
            'list_pages', 'get_page', 'create_page', 'update_page', 'delete_page', 'transfer_page', 'bulk_pages',
            'list_languages', 'list_media', 'get_config', 'get_users', 'get_packages', 'get_system_info', 'get_dashboard', 'get_webhooks', 'get_blueprint', 'discover_plugins',
        ],
        array_keys($byName)
    ) === [],
    'tools/list contains every pages tool and each domain'
);
check($byName['list_pages']['annotations']['readOnlyHint'] === true, 'list_pages is annotated read-only');
check($byName['delete_page']['annotations']['destructiveHint'] === true, 'delete_page is annotated destructive');
foreach ($descriptors as $descriptor) {
    check(
        ($descriptor['name'] ?? '') !== ''
        && ($descriptor['description'] ?? '') !== ''
        && ($descriptor['inputSchema']['type'] ?? '') === 'object',
        'every descriptor has name/description/object inputSchema'
    );
}

// Scope filtering is visibility only — the api plugin still enforces permissions.
$scoped = new ToolRegistry(null);
$scoped->configure(null, ['api.pages.read']);
$scopedNames = array_column($scoped->list(), 'name');
check(array_diff(['site_info', 'list_pages', 'get_page', 'list_languages'], $scopedNames) === [], 'a read-scoped key sees pages-read tools');
check(array_intersect(['create_page', 'update_config', 'manage_users', 'clear_cache'], $scopedNames) === [], 'a read-scoped key sees no write tools');
check(!$scoped->has('create_page'), 'a hidden tool is not callable');
$scoped->configure(null, []);
check(count($scoped->list()) === 50, 'an unscoped key sees everything');

// Hidden-vs-unknown: an existing-but-filtered tool names its missing permission.
$scoped->configure(null, ['api.pages.read']);
check($scoped->missingPermission('manage_users') === 'api.users.write', 'missingPermission names the gate of a hidden tool');
check($scoped->missingPermission('list_pages') === null, 'missingPermission is null for a visible tool');
check($scoped->missingPermission('no_such_tool') === null, 'missingPermission is null for an unknown tool');
$access = $scoped->toolAccess();
check(
    $access['visible'] + $access['hidden'] === 50
    && in_array('manage_users', $access['hidden_by_missing_permission']['api.users.write'] ?? [], true),
    'toolAccess partitions the surface and groups hidden tools by permission'
);
$scoped->configure(null, []);

$permissionMap = (new ToolRegistry(null))->permissionMap();
check(
    in_array('site_info', $permissionMap[''] ?? [], true)
    && in_array('list_pages', $permissionMap['api.pages.read'] ?? [], true)
    && in_array('manage_packages', $permissionMap['api.gpm.write'] ?? [], true),
    'permissionMap groups tools by their gating permission'
);

$call = $server->dispatch(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'site_info', 'arguments' => []]]);
check($call['result']['isError'] === false, 'tools/call site_info succeeds outside Grav');

$badTool = $server->dispatch(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nope']]);
check($badTool['error']['code'] === -32602, 'unknown tool is a -32602 protocol error');

$badMethod = $server->dispatch(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'nope']);
check($badMethod['error']['code'] === -32601, 'unknown method is a -32601 protocol error');

check($server->dispatch(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) === null, 'notifications produce no response');

check(McpServer::bearerToken('Bearer grav_abc') === 'grav_abc', 'bearerToken extracts the token');
check(McpServer::bearerToken('bearer grav_abc') === 'grav_abc', 'bearerToken is case-insensitive per RFC 6749');
check(McpServer::bearerToken('Basic dXNlcjpwdw==') === null, 'bearerToken ignores non-Bearer schemes');
check(McpServer::bearerToken('') === null, 'bearerToken ignores a missing header');

// --- Resources & prompts (phase 4) ---

$resourcesList = $server->dispatch(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/list']);
$resourceDescriptors = $resourcesList['result']['resources'];
check(count($resourceDescriptors) === 5, 'resources/list returns exactly 5 resources, got ' . count($resourceDescriptors));
foreach ($resourceDescriptors as $descriptor) {
    check(str_starts_with((string) ($descriptor['uri'] ?? ''), 'grav://'), 'every resource has a grav:// URI');
    check(($descriptor['mimeType'] ?? '') === 'application/json', 'every resource declares mimeType application/json');
}

$readOk = $server->dispatch(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/read', 'params' => ['uri' => 'grav://languages']]);
check(str_contains($readOk['result']['contents'][0]['text'], 'error'), 'resources/read of grav://languages outside Grav returns the error payload');

$readBad = $server->dispatch(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'resources/read', 'params' => ['uri' => 'grav://nope']]);
check($readBad['error']['code'] === -32002, 'unknown resource URI is a -32002 protocol error');

$promptsList = $server->dispatch(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'prompts/list']);
check(count($promptsList['result']['prompts']) === 6, 'prompts/list returns exactly 6 prompts, got ' . count($promptsList['result']['prompts']));

$promptGet = $server->dispatch(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'prompts/get', 'params' => ['name' => 'create_blog_post', 'arguments' => ['topic' => 'X']]]);
check(str_contains($promptGet['result']['messages'][0]['content']['text'], 'X'), 'prompts/get create_blog_post interpolates the topic argument');

$promptBad = $server->dispatch(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'prompts/get', 'params' => ['name' => 'nope']]);
check($promptBad['error']['code'] === -32602, 'unknown prompt is a -32602 protocol error');

// --- OAuth: pure protocol pieces ---

// RFC 7636 appendix B test vector
check(
    OAuthServer::verifyPkce('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'),
    'PKCE S256 verifies the RFC 7636 test vector'
);
check(!OAuthServer::verifyPkce('wrong-verifier-wrong-verifier-wrong-verifier', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'), 'PKCE rejects a wrong verifier');
check(!OAuthServer::verifyPkce('anything', ''), 'PKCE rejects an empty challenge');

$as = OAuthServer::authorizationServerMetadata('https://example.com', '/mcp');
check($as['issuer'] === 'https://example.com', 'AS metadata issuer');
check($as['authorization_endpoint'] === 'https://example.com/mcp/oauth/authorize', 'AS metadata authorize endpoint');
check($as['token_endpoint'] === 'https://example.com/mcp/oauth/token', 'AS metadata token endpoint');
check($as['registration_endpoint'] === 'https://example.com/mcp/oauth/register', 'AS metadata registration endpoint');
check($as['revocation_endpoint'] === 'https://example.com/mcp/oauth/revoke', 'AS metadata revocation endpoint');
check($as['code_challenge_methods_supported'] === ['S256'], 'AS metadata requires S256 PKCE');

$pr = OAuthServer::protectedResourceMetadata('https://example.com', '/mcp');
check($pr['resource'] === 'https://example.com/mcp', 'resource metadata resource URL');
check($pr['authorization_servers'] === ['https://example.com'], 'resource metadata points at this site as AS');

// --- OAuth scopes: vocabulary and request filtering ---

check(!array_key_exists('scopes_supported', $as), 'metadata omits scopes_supported when no vocabulary is passed');
$supported = OAuthServer::supportedScopes();
check(in_array('api.pages.read', $supported, true) && in_array('api.gpm.write', $supported, true), 'supportedScopes derives the tool permissions from ToolRegistry');
check(!in_array('', $supported, true), 'supportedScopes drops the no-permission bucket');
$asScoped = OAuthServer::authorizationServerMetadata('https://example.com', '/mcp', $supported);
check(($asScoped['scopes_supported'] ?? []) === $supported, 'AS metadata advertises the supported scopes');
check((OAuthServer::protectedResourceMetadata('https://example.com', '/mcp', $supported)['scopes_supported'] ?? []) === $supported, 'resource metadata advertises the supported scopes');

check(
    OAuthServer::filterScopes(" api.pages.read openid email api.pages.read admin.super * \n") === ['api.pages.read', 'admin.super', '*'],
    'filterScopes keeps api.* / admin.super / * entries, deduplicated, and drops the rest'
);
check(OAuthServer::filterScopes('') === [], 'filterScopes of an empty request is empty (unscoped)');
check(OAuthServer::filterScopes('openid profile email') === [], 'filterScopes of a wholly unrecognized request is empty');
check(OAuthServer::filterScopes('api') === [], 'filterScopes drops a bare "api" (only api.* leaves are scopes)');

// --- Scoped-key tool visibility (issue #16) ---

$scoped = new ToolRegistry();
$scoped->configure(null, ['api.pages.read']);
$scopedNames = array_column($scoped->list(), 'name');
check(in_array('list_pages', $scopedNames, true), 'a pages-read key sees list_pages');
check(!in_array('update_site_dashboard_layout', $scopedNames, true), 'a pages-read key does not see the super-only site dashboard tool');
check(!in_array('manage_users', $scopedNames, true), 'a pages-read key does not see user-write tools');

// --- OAuth store: lockout + revocation mechanics (phase 5) ---

$storeFile = sys_get_temp_dir() . '/mcp-oauth-smoke-' . getmypid() . '.json';
@unlink($storeFile);

$store = new OAuthStore($storeFile);
check($store->failureCount('ip:1.2.3.4') === 0, 'an unknown throttle key has no failures');
$store->recordFailure('ip:1.2.3.4', 900);
$store->recordFailure('ip:1.2.3.4', 900);
check($store->failureCount('ip:1.2.3.4') === 2, 'recordFailure increments the count');
check((new OAuthStore($storeFile))->failureCount('ip:1.2.3.4') === 2, 'failure counts survive a new instance');
$store->clearFailures('ip:1.2.3.4');
check($store->failureCount('ip:1.2.3.4') === 0, 'clearFailures resets the count');

$store->recordFailure('user:bob', 0);
check($store->failureCount('user:bob') === 0, 'an expired failure window counts as zero');

$store->putRefresh('a', ['client_id' => 'c', 'username' => 'bob', 'key_id' => 'k1', 'expires' => time() + 60]);
$store->putRefresh('b', ['client_id' => 'c', 'username' => 'bob', 'key_id' => 'k2', 'expires' => time() + 60]);
$store->deleteRefreshByKeyId('k1');
check($store->takeRefresh('a') === null, 'deleteRefreshByKeyId drops the matching refresh token');
check($store->takeRefresh('b') !== null, 'deleteRefreshByKeyId leaves other refresh tokens alone');

$store->putCode('c1', ['client_id' => 'c', 'username' => 'bob', 'expires' => time() + 60]);
check(is_array($store->takeCode('c1')), 'takeCode returns the stored code');
check($store->takeCode('c1') === null, 'authorization codes are single-use');

// Concurrency: two instances = two simultaneous requests, each constructed
// while the code exists. take* re-reads under an exclusive lock, so the
// second redemption must lose even though its constructor snapshot is stale.
$store->putCode('race', ['client_id' => 'c', 'expires' => time() + 60]);
$firstInstance = new OAuthStore($storeFile);
$secondInstance = new OAuthStore($storeFile);
check(is_array($firstInstance->takeCode('race')), 'the first concurrent redemption wins');
check($secondInstance->takeCode('race') === null, 'the second concurrent redemption loses (reload under lock)');

// Refresh replay: the first take marks the token used; a later take returns
// the tombstone so the server can treat replay as theft and sweep the family.
$store->putRefresh('gen1', ['client_id' => 'c', 'username' => 'bob', 'key_id' => 'kOld', 'family' => 'famX', 'expires' => time() + 60]);
$liveTake = $store->takeRefresh('gen1');
check(is_array($liveTake) && empty($liveTake['used']), 'a live refresh token comes back without the used flag');
$replayTake = $store->takeRefresh('gen1');
check(is_array($replayTake) && !empty($replayTake['used']), 'a replayed refresh token comes back flagged used');
$store->putRefresh('gen2', ['client_id' => 'c', 'username' => 'bob', 'key_id' => 'kNew', 'family' => 'famX', 'expires' => time() + 60]);
check((new OAuthStore($storeFile))->revokeFamily('famX') === ['kNew'], 'revokeFamily sweeps the family and returns the live keys to revoke');
check($store->takeRefresh('gen2') === null, 'the swept descendant refresh token is gone');
check($store->revokeFamily('') === [], 'an empty family never sweeps (pre-family tokens all share the missing value)');

// Registration-time pruning: stale clients drop, referenced and recent stay.
$store->putClient(['client_id' => 'stale', 'created' => time() - 200 * 86400]);
$store->putClient(['client_id' => 'referenced', 'created' => time() - 200 * 86400]);
$store->putRefresh('r-ref', ['client_id' => 'referenced', 'username' => 'bob', 'key_id' => 'k9', 'expires' => time() + 60]);
$store->putClient(['client_id' => 'fresh', 'created' => time()]);
check($store->getClient('stale') === null, 'putClient prunes old clients nothing references');
check($store->getClient('referenced') !== null, 'putClient keeps clients with a live refresh token');
check($store->getClient('fresh') !== null, 'putClient keeps the client being registered');

// The pending cap: a registration flood inside the day window cannot grow the
// store past MAX_PENDING — oldest unconsented clients are evicted, clients
// referenced by a live token never are.
$capFile = sys_get_temp_dir() . '/mcp-oauth-smoke-cap-' . getmypid() . '.json';
@unlink($capFile);
$capStore = new OAuthStore($capFile);
$capStore->putClient(['client_id' => 'live-client', 'created' => time() - 3600]);
$capStore->putRefresh('r-live', ['client_id' => 'live-client', 'username' => 'bob', 'key_id' => 'kL', 'expires' => time() + 60]);
for ($i = 0; $i <= OAuthStore::MAX_PENDING; $i++) { // one more than the cap
    $capStore->putClient(['client_id' => "flood-{$i}", 'created' => time() - 600 + $i]);
}
check($capStore->getClient('flood-0') === null, 'the cap evicts the oldest unconsented client');
check($capStore->getClient('flood-' . OAuthStore::MAX_PENDING) !== null, 'the cap keeps the newest registration');
check($capStore->getClient('live-client') !== null, 'the cap never evicts a client with a live token');
$capData = (array) json_decode((string) file_get_contents($capFile), true);
check(count($capData['clients']) === OAuthStore::MAX_PENDING + 1, 'the store holds exactly MAX_PENDING pending clients plus the live one');
@unlink($capFile);

// A corrupted store self-heals instead of TypeError-ing into a 500.
file_put_contents($storeFile, json_encode(['refresh_tokens' => ['x' => 'not-an-array'], 'failures' => ['y' => 'junk']]));
$corrupt = new OAuthStore($storeFile);
check($corrupt->failureCount('y') === 0, 'a corrupt failure entry counts as zero');
$corrupt->deleteRefreshByKeyId('anything');
check($corrupt->takeRefresh('x') === null, 'corrupt refresh entries are dropped, not fatal');

@unlink($storeFile);

// serverInfo.version is reported to every MCP client, so it must match the
// version GPM installs. These drifted the first time blueprints.yaml was bumped.
$blueprints = (string) file_get_contents(__DIR__ . '/../blueprints.yaml');
preg_match('/^version:\s*(\S+)/m', $blueprints, $m);
check(
    ($m[1] ?? '') === McpServer::VERSION,
    sprintf('McpServer::VERSION (%s) matches blueprints.yaml (%s)', McpServer::VERSION, $m[1] ?? 'missing')
);

// The runtime outdated-api warning compares against MIN_API_VERSION; keep it
// honest against the dependency floor blueprints.yaml declares to GPM.
preg_match('/name:\s*api,\s*version:\s*"?>=([0-9.]+)"?/', $blueprints, $m);
check(
    ($m[1] ?? '') === McpServer::MIN_API_VERSION,
    sprintf('McpServer::MIN_API_VERSION (%s) matches the blueprints.yaml api floor (%s)', McpServer::MIN_API_VERSION, $m[1] ?? 'missing')
);

echo "smoke: OK\n";
