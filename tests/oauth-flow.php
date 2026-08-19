<?php

declare(strict_types=1);

/**
 * OAuth 2.1 flow test: drives OAuthServer through register → authorize →
 * consent → token → refresh → revoke against real Grav + api-plugin classes,
 * asserting the security behaviors that matter:
 *
 *   - redirect-host allowlist at registration
 *   - open-redirect guard at authorize
 *   - consent-form HMAC (tamper/expiry) integrity
 *   - brute-force lockout by IP and by username
 *   - permission gate distinct from bad credentials
 *   - PKCE S256 enforcement and single-use codes
 *   - refresh rotation revoking the superseded access key
 *   - RFC 7009 revocation killing both token halves, with no validity oracle
 *
 * OAuthServer's handlers respond-and-exit like real HTTP, so each request runs
 * in a CHILD php process (this same file, --child), mirroring production's
 * process-per-request. State flows between requests through the real
 * OAuthStore/ApiKeyManager files in a per-run temp dir. header(),
 * http_response_code() and usleep() are called unqualified inside the OAuth
 * namespace, so namespace-local shims below capture responses and skip the
 * anti-brute-force sleeps without touching production code.
 *
 * Needs .gravtest/grav-admin with the api plugin (skips cleanly otherwise):
 *
 *   docker run --rm -v "$PWD:/app" php:8.3-cli php /app/tests/oauth-flow.php
 */

namespace Grav\Plugin\McpServer\OAuth {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        $GLOBALS['__test_headers'][] = $header;
    }

    function http_response_code(int $response_code = 0): int|bool
    {
        if ($response_code !== 0) {
            $GLOBALS['__test_status'] = $response_code;
        }

        return $GLOBALS['__test_status'] ?? 200;
    }

    function usleep(int $microseconds): void
    {
    }

    // php://input is empty in the CLI SAPI; the child harness parks the piped
    // request body in a global instead. Everything else (OAuthStore's real
    // file reads) passes through untouched.
    function file_get_contents(string $filename, mixed ...$args): string|false
    {
        if ($filename === 'php://input') {
            return (string) ($GLOBALS['__test_input'] ?? '');
        }

        return \file_get_contents($filename, ...$args);
    }
}

namespace {

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Authentication;
use Grav\Common\User\DataUser\User;
use Grav\Plugin\McpServer\OAuth\OAuthServer;

$gravRoot = __DIR__ . '/../.gravtest/grav-admin';
if (!is_file($gravRoot . '/vendor/autoload.php') || !is_file($gravRoot . '/user/plugins/api/classes/Api/PermissionResolver.php')) {
    echo "oauth-flow: SKIP (no .gravtest/grav-admin with the api plugin)\n";
    exit(0);
}

require $gravRoot . '/vendor/autoload.php';
require_once $gravRoot . '/user/plugins/api/classes/Api/PermissionResolver.php';
require_once $gravRoot . '/user/plugins/api/classes/Api/Auth/ApiKeyManager.php';
require_once __DIR__ . '/../classes/OAuth/OAuthStore.php';
require_once __DIR__ . '/../classes/OAuth/OAuthServer.php';

// --- Shared fixtures (parent and child agree on these) -----------------------

const REDIRECT_URI = 'https://client.example/callback';
const VERIFIER = 'test-verifier-test-verifier-test-verifier-abc';  // 45 chars, PKCE charset

function pkceChallenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function makeUser(array $data, bool $exists = true): User
{
    return new class($data, $exists) extends User {
        public function __construct(array $data, private readonly bool $existsFlag)
        {
            parent::__construct($data);
        }

        public function exists(): bool
        {
            return $this->existsFlag;
        }

        // Fixture users have no backing file; authenticate()'s opportunistic
        // rehash must not try to persist one.
        public function save(...$args)
        {
        }
    };
}

// --- Child mode: run ONE request against OAuthServer and dump the response ---

if (($argv[1] ?? '') === '--child') {
    $spec = json_decode(base64_decode($argv[2]), true);

    $GLOBALS['__test_headers'] = [];
    $GLOBALS['__test_status'] = 200;
    $GLOBALS['__test_input'] = stream_get_contents(STDIN);

    // Children are separate processes, so they dump their own coverage when a
    // coverage audit asks for it (harmless no-op otherwise).
    if (($covDir = getenv('FLOW_COVERAGE_DIR')) && function_exists('xdebug_start_code_coverage')) {
        register_shutdown_function(static function () use ($covDir): void {
            file_put_contents($covDir . '/cov-flow-' . getmypid() . '-' . uniqid() . '.json', json_encode(xdebug_get_code_coverage()));
        });
        xdebug_start_code_coverage(XDEBUG_CC_UNUSED);
    }

    $_SERVER['REQUEST_METHOD'] = $spec['method'];
    $_SERVER['REMOTE_ADDR'] = $spec['ip'] ?? '10.0.0.1';
    // Grav's Uri::ip() reads getenv(), not $_SERVER — without this every child
    // shares the fail-closed 'UNKNOWN' lockout bucket.
    putenv('REMOTE_ADDR=' . $_SERVER['REMOTE_ADDR']);
    $_GET = $spec['get'] ?? [];
    $_POST = $spec['post'] ?? [];

    $dataDir = $spec['dataDir'];
    @mkdir($dataDir, 0777, true);

    $hash = static fn(string $pw): string => Authentication::create($pw);
    $users = [
        'alice' => makeUser(['username' => 'alice', 'email' => 'alice@example.com', 'state' => 'enabled',
            'hashed_password' => $hash('pw-alice'), 'access' => ['api' => ['access' => true]]]),
        'bob' => makeUser(['username' => 'bob', 'email' => 'bob@example.com', 'state' => 'enabled',
            'hashed_password' => $hash('pw-bob'), 'access' => ['api' => ['access' => true]]]),
        'noperm' => makeUser(['username' => 'noperm', 'email' => 'noperm@example.com', 'state' => 'enabled',
            'hashed_password' => $hash('pw-noperm'), 'access' => []]),
        'sleepy' => makeUser(['username' => 'sleepy', 'email' => 'sleepy@example.com', 'state' => 'disabled',
            'hashed_password' => $hash('pw-sleepy'), 'access' => ['api' => ['access' => true]]]),
    ];

    $grav = Grav::instance();
    // Grav::instance() resolves (and thereby freezes) some services during its
    // own boot; Pimple's offsetUnset clears both the value and the frozen flag.
    foreach (['config', 'uri', 'locator', 'accounts'] as $service) {
        unset($grav[$service]);
    }
    $grav['config'] = new Config([
        'security' => ['salt' => 'oauth-flow-test-salt'],
        'site' => ['title' => 'OAuth Flow Test'],
        'plugins' => [
            'mcp-server' => ['oauth' => [
                'allowed_redirect_hosts' => ['client.example'],
                'require_permission' => 'api.access',
                'access_token_days' => 7,
                'refresh_token_days' => 90,
            ]],
            // ttl 0 disables the bcrypt verify cache so ApiKeyManager never
            // touches the (absent) cache service in this container.
            'api' => ['auth' => ['key_cache_ttl' => 0]],
        ],
    ]);
    $grav['uri'] = new class {
        public function rootUrl(bool $include = false): string
        {
            return 'https://site.test';
        }
    };
    $grav['locator'] = new class($dataDir) {
        public function __construct(private readonly string $dir)
        {
        }

        public function findResource(string $uri, bool $absolute = true, bool $create = false): string
        {
            return $this->dir;
        }
    };
    $grav['accounts'] = new class($users) {
        public function __construct(private readonly array $users)
        {
        }

        public function find(string $query, array $fields = []): ?User
        {
            foreach ($this->users as $user) {
                if ($user->get('username') === $query || $user->get('email') === $query) {
                    return $user;
                }
            }

            return null;
        }

        public function load(string $username): User
        {
            return $this->users[$username] ?? makeUser(['username' => $username], false);
        }
    };

    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        fwrite(STDOUT, json_encode([
            'status' => $GLOBALS['__test_status'],
            'headers' => $GLOBALS['__test_headers'],
            'body' => $body,
        ]));
    });

    (new OAuthServer($grav, '/mcp'))->handle($spec['path']);
}

// --- Parent mode: drive the flow -------------------------------------------

define('FLOW_DATA_DIR', sys_get_temp_dir() . '/mcp-oauth-flow-' . getmypid());
@mkdir(FLOW_DATA_DIR, 0777, true);

$failed = 0;
function check(bool $ok, string $what): void
{
    global $failed;
    if (!$ok) {
        echo "  FAIL {$what}\n";
        $failed++;
    }
}

/** Run one request in a child process; body for php://input goes via stdin. */
function oauth(string $method, string $path, array $get = [], array $post = [], string $stdin = '', string $ip = '10.0.0.1'): array
{
    $spec = base64_encode((string) json_encode([
        'method' => $method, 'path' => $path, 'get' => $get, 'post' => $post,
        'ip' => $ip, 'dataDir' => FLOW_DATA_DIR,
    ]));
    $proc = proc_open(
        [PHP_BINARY, __FILE__, '--child', $spec],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $envelope = json_decode((string) $out, true);
    if (!is_array($envelope)) {
        fwrite(STDERR, "oauth-flow: child crashed for {$method} {$path}\n{$err}\n{$out}\n");
        exit(1);
    }
    if ($err !== '' && $err !== false) {
        // A fatal after ob_start still yields a clean envelope with an empty
        // body — never let a child error pass silently.
        fwrite(STDERR, "oauth-flow: child stderr for {$method} {$path}:\n{$err}\n");
    }
    if (getenv('FLOWDEBUG')) {
        fwrite(STDERR, sprintf(
            "DBG %s %s -> %d | %s | %s\n",
            $method,
            $path,
            $envelope['status'],
            json_encode($envelope['headers']),
            preg_match('/class="error">([^<]+)</', (string) $envelope['body'], $m) ? $m[1] : substr(strip_tags((string) $envelope['body']), 0, 80)
        ));
    }

    return $envelope;
}

/** The query params a 302 Location redirect carried, or null if no redirect. */
function redirectParams(array $response): ?array
{
    foreach ($response['headers'] as $header) {
        if (str_starts_with($header, 'Location: ')) {
            parse_str((string) parse_url(substr($header, 10), PHP_URL_QUERY), $params);

            return $params;
        }
    }

    return null;
}

/** Pull the ts/sig hidden fields out of a rendered consent form. */
function consentSignature(string $html): array
{
    preg_match('/name="ts" value="(\d+)"/', $html, $ts);
    preg_match('/name="sig" value="([0-9a-f]+)"/', $html, $sig);

    return ['ts' => $ts[1] ?? '', 'sig' => $sig[1] ?? ''];
}

$authParams = static fn(string $clientId): array => [
    'client_id' => $clientId,
    'redirect_uri' => REDIRECT_URI,
    'response_type' => 'code',
    'code_challenge' => pkceChallenge(VERIFIER),
    'code_challenge_method' => 'S256',
    'state' => 'state-xyz',
    'scope' => '',
    'resource' => '',
];

/** authorize → consent → approve, returning the authorization code. */
function obtainCode(string $clientId, array $params, string $username, string $password): string
{
    $consent = oauth('GET', '/mcp/oauth/authorize', $params);
    check($consent['status'] === 200, 'authorize renders the consent form');
    $post = $params + consentSignature($consent['body']) + ['username' => $username, 'password' => $password];
    $approved = oauth('POST', '/mcp/oauth/authorize', [], $post);
    $redirect = redirectParams($approved);
    check(isset($redirect['code']), 'approval redirects back with a code');

    return (string) ($redirect['code'] ?? '');
}

// 1. Discovery metadata.
$meta = oauth('GET', '/.well-known/oauth-authorization-server');
$metaBody = json_decode($meta['body'], true);
check($meta['status'] === 200 && $metaBody['issuer'] === 'https://site.test', 'AS metadata served with correct issuer');
check($metaBody['code_challenge_methods_supported'] === ['S256'], 'metadata advertises S256 only');

// 2. Registration enforces the redirect-host allowlist.
$evil = oauth('POST', '/mcp/oauth/register', [], [], '{"redirect_uris":["https://evil.example/cb"],"client_name":"Evil"}');
check($evil['status'] === 400 && str_contains($evil['body'], 'invalid_redirect_uri'), 'registration rejects a non-allowlisted redirect host');

$plainHttp = oauth('POST', '/mcp/oauth/register', [], [], '{"redirect_uris":["http://client.example/cb"]}');
check($plainHttp['status'] === 400, 'registration rejects plain http on a non-localhost host');

$reg = oauth('POST', '/mcp/oauth/register', [], [], '{"redirect_uris":["' . REDIRECT_URI . '"],"client_name":"Flow Test"}');
$client = json_decode($reg['body'], true);
check($reg['status'] === 201 && is_string($client['client_id'] ?? null), 'registration succeeds for an allowlisted https host');
$clientId = (string) $client['client_id'];

// 3. Authorize: open-redirect guard and PKCE requirement.
$badRedirect = oauth('GET', '/mcp/oauth/authorize', ['client_id' => $clientId, 'redirect_uri' => 'https://client.example/other'] + $authParams($clientId));
check($badRedirect['status'] === 400 && redirectParams($badRedirect) === null, 'unregistered redirect_uri gets an error page, never a redirect');

$noPkce = oauth('GET', '/mcp/oauth/authorize', array_merge($authParams($clientId), ['code_challenge' => '', 'code_challenge_method' => '']));
$noPkceRedirect = redirectParams($noPkce);
check(($noPkceRedirect['error'] ?? '') === 'invalid_request', 'missing PKCE is refused');
check(($noPkceRedirect['iss'] ?? '') === 'https://site.test', 'error redirects carry the RFC 9207 iss parameter');

// 4. Consent-form integrity: a tampered redirect_uri invalidates the signature.
$consent = oauth('GET', '/mcp/oauth/authorize', $authParams($clientId));
$sig = consentSignature($consent['body']);
check($sig['sig'] !== '', 'consent form carries an HMAC signature');
$tampered = oauth('POST', '/mcp/oauth/authorize', [], array_merge($authParams($clientId), $sig, [
    'redirect_uri' => 'https://client.example/other', 'username' => 'alice', 'password' => 'pw-alice',
]));
check($tampered['status'] === 400 && redirectParams($tampered) === null, 'tampering with signed form params is rejected');

$staleSig = oauth('POST', '/mcp/oauth/authorize', [], array_merge($authParams($clientId), ['ts' => (string) (time() - 10), 'sig' => $sig['sig']], [
    'username' => 'alice', 'password' => 'pw-alice',
]));
check($staleSig['status'] === 400, 'an expired or re-stamped form is rejected');

// 5. Credential failures are indistinguishable and throttled.
$freshPost = static function (array $extra) use ($authParams, $clientId): array {
    $consent = oauth('GET', '/mcp/oauth/authorize', $authParams($clientId));

    return array_merge($authParams($clientId), consentSignature($consent['body']), $extra);
};

$wrongPw = oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'bob', 'password' => 'nope']), '', '9.9.9.9');
check(str_contains($wrongPw['body'], 'Invalid credentials'), 'wrong password re-renders with a generic error');

$disabled = oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'sleepy', 'password' => 'pw-sleepy']));
check(str_contains($disabled['body'], 'Invalid credentials'), 'a disabled account is indistinguishable from a bad password');

for ($i = 0; $i < 4; $i++) { // 4 more failures on bob → 5 total from 9.9.9.9
    oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'bob', 'password' => 'nope']), '', '9.9.9.9');
}
$lockedIp = oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'bob', 'password' => 'pw-bob']), '', '9.9.9.9');
check(str_contains($lockedIp['body'], 'Too many failed attempts'), 'correct password is locked out after 5 failures from one IP');
$lockedUser = oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'bob', 'password' => 'pw-bob']), '', '8.8.8.8');
check(str_contains($lockedUser['body'], 'Too many failed attempts'), 'the username stays locked even from a fresh IP');

// 6. Permission gate: right password, insufficient account.
$noPerm = oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'noperm', 'password' => 'pw-noperm']));
check(str_contains($noPerm['body'], 'api.access'), 'an account without the consent permission is told which permission it lacks');

// 7. Deny.
$denied = oauth('POST', '/mcp/oauth/authorize', [], $freshPost(['username' => 'alice', 'password' => 'pw-alice', 'deny' => '1']));
check((redirectParams($denied)['error'] ?? '') === 'access_denied', 'deny redirects back with access_denied');

// 8. Approve: code issued, state echoed, iss present.
$consent = oauth('GET', '/mcp/oauth/authorize', $authParams($clientId));
$approved = oauth('POST', '/mcp/oauth/authorize', [], array_merge($authParams($clientId), consentSignature($consent['body']), [
    'username' => 'alice', 'password' => 'pw-alice',
]));
$grant = redirectParams($approved);
check(isset($grant['code']) && $grant['state'] === 'state-xyz' && $grant['iss'] === 'https://site.test', 'approval carries code, state, and iss');

// 9. Token endpoint: PKCE enforced, codes are single-use.
$tokenPost = static fn(string $code, string $verifier, string $cid = ''): array => [
    'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
    'client_id' => $cid !== '' ? $cid : $GLOBALS['clientId'], 'redirect_uri' => REDIRECT_URI,
];
$GLOBALS['clientId'] = $clientId;

$badVerifier = oauth('POST', '/mcp/oauth/token', [], $tokenPost((string) $grant['code'], 'wrong-verifier-wrong-verifier-wrong-verifier'));
check($badVerifier['status'] === 400 && str_contains($badVerifier['body'], 'invalid_grant'), 'a wrong PKCE verifier is refused');
$burned = oauth('POST', '/mcp/oauth/token', [], $tokenPost((string) $grant['code'], VERIFIER));
check($burned['status'] === 400, 'a code is consumed by any redemption attempt (single-use)');

$code2 = obtainCode($clientId, $authParams($clientId), 'alice', 'pw-alice');
$wrongClient = oauth('POST', '/mcp/oauth/token', [], $tokenPost($code2, VERIFIER, 'not-the-client'));
check($wrongClient['status'] === 400, 'a code is bound to its client_id');

$code3 = obtainCode($clientId, $authParams($clientId), 'alice', 'pw-alice');
$issued = json_decode(oauth('POST', '/mcp/oauth/token', [], $tokenPost($code3, VERIFIER))['body'], true);
check(str_starts_with((string) ($issued['access_token'] ?? ''), 'grav_'), 'redemption issues a grav_ API key');
check(is_string($issued['refresh_token'] ?? null), 'redemption issues a refresh token');

$keysFile = FLOW_DATA_DIR . '/api-keys.yaml';
check(is_file($keysFile) && str_contains((string) file_get_contents($keysFile), 'alice'), 'the access token is a real key in the api key store');

// 10. Refresh rotation: old refresh dies, old access key is revoked.
$keysBefore = (string) file_get_contents($keysFile);
$refreshPost = static fn(string $token): array => ['grant_type' => 'refresh_token', 'refresh_token' => $token, 'client_id' => $GLOBALS['clientId']];
$rotated = json_decode(oauth('POST', '/mcp/oauth/token', [], $refreshPost((string) $issued['refresh_token']))['body'], true);
check(str_starts_with((string) ($rotated['access_token'] ?? ''), 'grav_') && $rotated['access_token'] !== $issued['access_token'], 'refresh issues a new access key');
$reused = oauth('POST', '/mcp/oauth/token', [], $refreshPost((string) $issued['refresh_token']));
check($reused['status'] === 400, 'a refresh token is single-use (rotation)');
$keysAfter = (string) file_get_contents($keysFile);
$countKeys = static fn(string $yaml): int => substr_count($yaml, 'username: alice');
check($countKeys($keysAfter) === $countKeys($keysBefore), 'rotation revoked the superseded access key (no key pile-up)');

// 11. Revocation: killing the refresh token kills the access key too; no oracle.
$revoked = oauth('POST', '/mcp/oauth/revoke', [], ['token' => (string) $rotated['refresh_token']]);
check($revoked['status'] === 200, 'revocation returns 200');
$afterRevoke = oauth('POST', '/mcp/oauth/token', [], $refreshPost((string) $rotated['refresh_token']));
check($afterRevoke['status'] === 400, 'a revoked refresh token is dead');
check(!str_contains((string) file_get_contents($keysFile), substr((string) $rotated['access_token'], 0, 12)), 'revoking the refresh token revoked its access key');
$unknown = oauth('POST', '/mcp/oauth/revoke', [], ['token' => 'grav_' . str_repeat('0', 48)]);
check($unknown['status'] === 200, 'revoking an unknown token still returns 200 (no validity oracle)');

// Cleanup.
array_map('unlink', glob(FLOW_DATA_DIR . '/mcp-server/*') ?: []);
@rmdir(FLOW_DATA_DIR . '/mcp-server');
array_map('unlink', glob(FLOW_DATA_DIR . '/*') ?: []);
@rmdir(FLOW_DATA_DIR);

echo $failed === 0 ? "oauth-flow: OK\n" : "oauth-flow: {$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);

}
