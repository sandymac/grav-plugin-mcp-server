<?php

declare(strict_types=1);

/**
 * Checks the OAuth consent permission gate against real Grav + api-plugin classes.
 *
 * Unlike smoke.php this needs a Grav install with the api plugin, so it reads the
 * gitignored .gravtest/ tree (see DECISIONS.md "Testing strategy") and skips politely
 * when that isn't there:
 *
 *   docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/permission-gate.php
 *
 * It exists because this gate has failed twice in ways nothing else caught: core
 * $user->authorize() needs a login session we don't have, and its two
 * implementations disagree on what a scope argument even means.
 */

$grav = __DIR__ . '/../.gravtest/grav-admin';
if (!is_file($grav . '/vendor/autoload.php') || !is_file($grav . '/user/plugins/api/classes/Api/PermissionResolver.php')) {
    echo "permission-gate: SKIP (no .gravtest/grav-admin with the api plugin)\n";
    exit(0);
}

require $grav . '/vendor/autoload.php';
require_once $grav . '/user/plugins/api/classes/Api/PermissionResolver.php';
require_once __DIR__ . '/../classes/OAuth/OAuthStore.php';
require_once __DIR__ . '/../classes/OAuth/OAuthServer.php';
require_once __DIR__ . '/../classes/ApiBridge.php';
require_once __DIR__ . '/../classes/McpServer.php';
require_once __DIR__ . '/../classes/ToolRegistry.php';
foreach (glob(__DIR__ . '/../classes/Tools/*.php') as $toolFile) {
    require_once $toolFile;
}

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\DataUser\User;
use Grav\Plugin\McpServer\OAuth\OAuthServer;

Grav::instance()['config'] = new Config(['groups' => [
    'editors' => ['access' => ['api' => ['pages' => true]]],
    'operators' => ['access' => ['api' => ['access' => true]]],
]]);

// THE production predicate, not a mirror — extracting it (issue #13) is what
// makes drift between this test and authorizeSubmit() impossible.
$held = static fn(User $user, string $permission): bool => OAuthServer::accountMayConsent($user, $permission);

$cases = [
    // name, access map, groups, expected
    ['api.access + api.super', ['api' => ['access' => true, 'super' => true], 'site' => ['login' => true]], [], true],
    ['api.super only',        ['api' => ['super' => true]], [], true],
    ['api.access only',       ['api' => ['access' => true]], [], true],
    ['group grants api.access', [], ['operators'], true],
    ['group grants api.pages', [], ['editors'], false],
    ['site.login only',       ['site' => ['login' => true]], [], false],
    ['no access at all',      [], [], false],
    ['explicit api.access false', ['api' => ['access' => false, 'super' => false]], [], false],
];

$failed = 0;
foreach ($cases as [$name, $access, $groups, $expected]) {
    $user = new User(['username' => 'test', 'access' => $access, 'groups' => $groups]);
    $got = $held($user, 'api.access');
    if ($got !== $expected) {
        printf("  FAIL %s: expected %s, got %s\n", $name, var_export($expected, true), var_export($got, true));
        $failed++;
    }
}

// An empty require_permission means "any account that can authenticate".
$anyone = new User(['username' => 'test', 'access' => []]);
if (!$held($anyone, '')) {
    echo "  FAIL empty require_permission should allow anyone\n";
    $failed++;
}

// Tool visibility mirrors the account's resolved permissions: an unscoped key
// on a limited account only advertises tools the account can actually call.
$registry = new \Grav\Plugin\McpServer\ToolRegistry(null);

$reader = new User(['username' => 'bot', 'access' => ['api' => ['access' => true, 'pages' => ['read' => true]]]]);
$registry->configure(null, [], $reader);
$names = array_column($registry->list(), 'name');
foreach (['site_info' => true, 'list_pages' => true, 'manage_packages' => false, 'clear_cache' => false] as $tool => $expected) {
    if (in_array($tool, $names, true) !== $expected) {
        printf("  FAIL visibility: %s should be %s for pages-read account\n", $tool, $expected ? 'visible' : 'hidden');
        $failed++;
    }
}

// api.super is authority everywhere in the api plugin — sees the full surface.
$registry->configure(null, [], new User(['username' => 'root', 'access' => ['api' => ['super' => true]]]));
$superCount = count($registry->list());
$registry->configure(null, []); // no user: no account filter
if ($superCount !== count($registry->list())) {
    echo "  FAIL visibility: api.super account should see the full tool surface\n";
    $failed++;
}

echo $failed === 0 ? "permission-gate: OK\n" : "permission-gate: {$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
