<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\OAuth;

use Grav\Common\Grav;
use Grav\Common\Uri;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\PermissionResolver;

/**
 * Minimal OAuth 2.1 authorization server — exactly what the MCP authorization
 * spec (and therefore claude.ai's custom connectors) requires:
 *
 *   - RFC 8414 authorization-server metadata
 *   - RFC 9728 protected-resource metadata
 *   - RFC 7591 dynamic client registration (claude.ai registers itself)
 *   - authorization-code grant with mandatory PKCE S256 (public clients only)
 *   - rotating refresh tokens
 *
 * Access tokens ARE grav_ API keys minted through grav-plugin-api's
 * ApiKeyManager, so the MCP request path validates them with the same
 * ApiKeyAuthenticator as hand-generated keys, and `bin/plugin api keys:list`
 * shows and revokes them like any other key.
 */
class OAuthServer
{
    /** Hidden fields carried through the consent form, integrity-protected by an HMAC. */
    private const array FORM_PARAMS = [
        'client_id', 'redirect_uri', 'response_type',
        'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource',
    ];

    private const int CODE_TTL = 300;      // seconds an authorization code stays valid
    private const int FORM_TTL = 600;      // seconds the consent form stays submittable
    private const int MAX_FAILURES = 5;   // consent-login failures per IP or username...
    private const int LOCKOUT_TTL = 900;  // ...before that key is locked out this many seconds
    public const int MAX_REGISTRATIONS = 10; // DCR registrations per IP per LOCKOUT_TTL window (public for tests)

    /**
     * The Model Context Protocol wave mark, from the official logo
     * (modelcontextprotocol.io, MIT). The upstream file is the full "MCP"
     * wordmark on a 1338x195 canvas; this is the same three stroke paths with
     * the viewBox cropped square to the mark and the letterforms dropped.
     * `currentColor` so it inherits the page text colour.
     */
    private const string LOGO = <<<'SVG'
        <svg viewBox="0 0 195 195" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Model Context Protocol">
        <path d="M25 97.8528L92.8823 29.9706C102.255 20.598 117.451 20.598 126.823 29.9706V29.9706C136.196 39.3431 136.196 54.5391 126.823 63.9117L75.5581 115.177" stroke="currentColor" stroke-width="12" stroke-linecap="round"/>
        <path d="M76.2653 114.47L126.823 63.9117C136.196 54.5391 151.392 54.5391 160.765 63.9117L161.118 64.2652C170.491 73.6378 170.491 88.8338 161.118 98.2063L99.7248 159.6C96.6006 162.724 96.6006 167.789 99.7248 170.913L112.331 183.52" stroke="currentColor" stroke-width="12" stroke-linecap="round"/>
        <path d="M109.853 46.9411L59.6482 97.1457C50.2757 106.518 50.2757 121.714 59.6482 131.087V131.087C69.0208 140.459 84.2168 140.459 93.5894 131.087L143.794 80.8822" stroke="currentColor" stroke-width="12" stroke-linecap="round"/>
        </svg>
        SVG;

    private readonly OAuthStore $store;

    public function __construct(
        private readonly Grav $grav,
        private readonly string $route,
    ) {
        $this->store = OAuthStore::forGrav($grav);
    }

    /** Route an OAuth-related path. Always responds and exits. */
    public function handle(string $path): never
    {
        $base = rtrim($this->grav['uri']->rootUrl(true), '/');

        // Exact matches only, the same four paths McpServerPlugin::isOauthPath()
        // intercepts — a looser rule here would serve metadata at garbage
        // suffixes the moment the outer gate changes.
        if (in_array($path, ['/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server' . $this->route], true)) {
            $this->json(200, self::authorizationServerMetadata($base, $this->route, self::supportedScopes()));
        }
        if (in_array($path, ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource' . $this->route], true)) {
            $this->json(200, self::protectedResourceMetadata($base, $this->route, self::supportedScopes()));
        }

        match ($path) {
            $this->route . '/oauth/register' => $this->register(),
            $this->route . '/oauth/authorize' => $this->authorize($base),
            $this->route . '/oauth/token' => $this->token(),
            $this->route . '/oauth/revoke' => $this->revoke(),
            default => $this->json(404, ['error' => 'not_found']),
        };
    }

    /**
     * @param list<string> $scopes advertised as scopes_supported (omitted when empty)
     * @return array<string, mixed> RFC 8414 metadata (pure, testable without Grav)
     */
    public static function authorizationServerMetadata(string $base, string $route, array $scopes = []): array
    {
        return [
            'issuer' => $base,
            'authorization_endpoint' => $base . $route . '/oauth/authorize',
            'token_endpoint' => $base . $route . '/oauth/token',
            'registration_endpoint' => $base . $route . '/oauth/register',
            'revocation_endpoint' => $base . $route . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
        ] + ($scopes !== [] ? ['scopes_supported' => $scopes] : []);
    }

    /**
     * @param list<string> $scopes advertised as scopes_supported (omitted when empty)
     * @return array<string, mixed> RFC 9728 metadata (pure, testable without Grav)
     */
    public static function protectedResourceMetadata(string $base, string $route, array $scopes = []): array
    {
        return [
            'resource' => $base . $route,
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
        ] + ($scopes !== [] ? ['scopes_supported' => $scopes] : []);
    }

    /**
     * The scope vocabulary we advertise: the distinct permissions the MCP tools
     * enforce, straight from ToolRegistry — param-map CI keeps those in lockstep
     * with what the api plugin's routes actually check. Soft: bare-protocol
     * tests load OAuthServer without the tool classes, and metadata without
     * scopes_supported is still valid.
     *
     * @return list<string>
     */
    public static function supportedScopes(): array
    {
        if (!class_exists(\Grav\Plugin\McpServer\ToolRegistry::class)) {
            return [];
        }

        return array_values(array_filter(array_keys((new \Grav\Plugin\McpServer\ToolRegistry(null))->permissionMap())));
    }

    /**
     * Whether a granted scope set limits nothing: the wildcard, or coverage of
     * every advertised scope. Clients like claude.ai request the whole
     * scopes_supported list by default, and the consent screen must call that
     * "full account access" rather than imply a careful selection.
     */
    public static function coversAllSupported(array $scopes): bool
    {
        if (in_array('*', $scopes, true)) {
            return true;
        }

        $supported = self::supportedScopes();
        if ($supported === []) {
            return false; // no vocabulary to compare against (bare-protocol tests)
        }
        foreach ($supported as $permission) {
            if (!\Grav\Plugin\McpServer\ToolRegistry::scopeAllows($scopes, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a request limits nothing: no recognized scope at all, or a set
     * that covers the whole advertised vocabulary. Such a request is shown as
     * "full account access" and mints an unscoped key unless the user narrows.
     *
     * @param list<string> $requested recognized scopes from the request
     */
    public static function limitsNothing(array $requested): bool
    {
        return $requested === [] || self::coversAllSupported($requested);
    }

    /**
     * The scopes the consent screen offers as checkboxes: a request that limits
     * nothing offers the whole advertised vocabulary, a partial request offers
     * exactly what it asked for.
     *
     * @param list<string> $requested recognized scopes from the request ([] = none)
     * @return list<string>
     */
    private static function offeredScopes(array $requested): array
    {
        return self::limitsNothing($requested) ? self::supportedScopes() : $requested;
    }

    /**
     * Resolve what an approval grants from what the screen offered and what the
     * user left ticked (issue #1). The checkboxes are unsigned like the deny
     * button: intersecting with the offered list means forging them can only
     * narrow. Returns the scopes to store — [] is UNSCOPED, reached only when a
     * limit-nothing request keeps every offered scope with the freeze box off —
     * or null when nothing was kept, which the caller must refuse to mint.
     *
     * @param list<string> $requested recognized scopes from the request
     * @param list<string> $ticked    the scopes[] checkboxes that came back
     * @return list<string>|null
     */
    public static function resolveGrant(array $requested, array $ticked, bool $freeze): ?array
    {
        $offered = self::offeredScopes($requested);
        $kept = array_values(array_intersect($offered, $ticked));
        if ($offered !== [] && $kept === []) {
            return null;
        }

        return self::limitsNothing($requested) && !$freeze && count($kept) === count($offered) ? [] : $kept;
    }

    /**
     * The scope entries this server recognizes: api.* permissions (what the
     * tools enforce), admin.super, or the wildcard. Anything else — openid,
     * email, and other habits clients bring — is dropped; the client learns
     * what was granted from the token response's scope member (RFC 6749 §3.3).
     *
     * @return list<string> normalized and deduplicated
     */
    public static function filterScopes(string $scope): array
    {
        $kept = [];
        foreach (preg_split('/\s+/', trim($scope)) ?: [] as $entry) {
            if ($entry === '*' || $entry === 'admin.super' || str_starts_with($entry, 'api.')) {
                $kept[$entry] = true;
            }
        }

        return array_keys($kept);
    }

    /** RFC 7636: base64url(sha256(verifier)) must equal the stored challenge. */
    /**
     * The one consent-gate predicate: may this authenticated account approve MCP
     * access? tests/permission-gate.php calls exactly this method, so the test
     * cannot drift from the code that runs.
     *
     * Deliberately NOT $user->authorize(): both core implementations answer a
     * session-shaped question — they demand the `authenticated` property that
     * only the Login plugin's session flow sets, so they return false for every
     * account here. (Their 'test' scope escape hatch exists on Flex users only;
     * legacy UserTrait turns the scope into a prefix and asks for "test.api.login".)
     * The api plugin's PermissionResolver answers the question we actually have —
     * does this account hold the permission — from the access map alone, groups
     * included, and it's the same class its controllers gate on, so consent and
     * the minted key agree by construction.
     */
    public static function accountMayConsent(\Grav\Common\User\Interfaces\UserInterface $user, string $permission): bool
    {
        $resolver = new PermissionResolver();

        return $permission === ''
            || $resolver->resolve($user, $permission) === true
            // api.super is authority everywhere else in the api plugin; honour it here too.
            || $resolver->resolveExact($user, 'api.super') === true;
    }

    public static function verifyPkce(#[\SensitiveParameter] string $verifier, string $challenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return $challenge !== '' && hash_equals($challenge, $computed);
    }

    // --- Dynamic client registration (RFC 7591) ---

    private function register(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->json(405, ['error' => 'invalid_request']);
        }

        // Registration is public by spec (RFC 7591), so bound it: legitimate
        // connectors register once per setup, only a flood ever sees the 429.
        // Counted on success only — rejected floods then cost no store writes,
        // and the age prune plus MAX_PENDING still bound the file.
        $throttleKey = 'reg:' . Uri::ip();
        if ($this->store->failureCount($throttleKey) >= self::MAX_REGISTRATIONS) {
            $this->log('warning', sprintf('registration throttled: %d registrations from %s within %d minutes', self::MAX_REGISTRATIONS, Uri::ip(), intdiv(self::LOCKOUT_TTL, 60)));
            $this->json(429, ['error' => 'too_many_requests', 'error_description' => 'Too many client registrations from this address. Try again later.']);
        }

        $raw = (string) file_get_contents('php://input');
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            $this->rejectRegistration('invalid_client_metadata', 'request body must be a JSON object', $raw);
        }

        $uris = $meta['redirect_uris'] ?? [];
        if (!is_array($uris) || $uris === []) {
            $this->rejectRegistration('invalid_redirect_uri', 'redirect_uris is required', $raw);
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->redirectUriAllowed($uri)) {
                $this->rejectRegistration('invalid_redirect_uri', sprintf(
                    'redirect_uri %s is not allowed: must be https (or http on localhost) with a host listed in plugins.mcp-server.oauth.allowed_redirect_hosts',
                    json_encode($uri, JSON_UNESCAPED_SLASHES)
                ), $raw);
            }
        }

        $client = [
            'client_id' => bin2hex(random_bytes(16)),
            'client_name' => is_string($meta['client_name'] ?? null) ? mb_substr($meta['client_name'], 0, 100) : 'MCP client',
            'redirect_uris' => array_values($uris),
            'created' => time(),
        ];
        $this->store->putClient($client);
        $this->store->recordFailure($throttleKey, self::LOCKOUT_TTL);

        $this->json(201, $client + [
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ]);
    }

    /**
     * Refuse a registration and leave the request in the log. Hosted connectors
     * (claude.ai, Gemini) show the user only a generic "rejected" message, so
     * the site log is the one place the offending redirect_uri can be seen.
     */
    private function rejectRegistration(string $error, string $description, string $body): never
    {
        $this->log('warning', sprintf('registration rejected from %s: %s; request: %s',
            Uri::ip(), $description, mb_substr((string) preg_replace('/\s+/', ' ', $body), 0, 2000)));
        $this->json(400, ['error' => $error, 'error_description' => $description]);
    }

    private function redirectUriAllowed(string $uri): bool
    {
        $host = strtolower((string) (parse_url($uri, PHP_URL_HOST) ?? ''));
        $scheme = strtolower((string) (parse_url($uri, PHP_URL_SCHEME) ?? ''));
        $isLocal = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);

        if ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) {
            return false;
        }

        $allowed = array_map('strtolower', array_map('strval',
            (array) $this->grav['config']->get('plugins.mcp-server.oauth.allowed_redirect_hosts', [])));

        return $allowed === [] || in_array($host, $allowed, true) || $isLocal;
    }

    // --- Authorization endpoint ---

    private function authorize(string $base): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $this->authorizeSubmit();
        }

        $q = $_GET;
        $client = $this->store->getClient((string) ($q['client_id'] ?? ''));
        if ($client === null) {
            $this->html(400, '<p>Unknown <code>client_id</code>. Restart the connection from your MCP client.</p>');
        }

        $redirect = (string) ($q['redirect_uri'] ?? '');
        if (!in_array($redirect, $client['redirect_uris'], true)) {
            // Never redirect to an unregistered URI (open-redirect guard).
            $this->html(400, '<p><code>redirect_uri</code> is not registered for this client.</p>');
        }

        // From here on, errors go back to the client per RFC 6749.
        $params = $this->formParams($q);
        if ($params['response_type'] !== 'code') {
            $this->redirectBack($params, ['error' => 'unsupported_response_type']);
        }
        if ($params['code_challenge_method'] !== 'S256'
            || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $params['code_challenge'])) {
            $this->redirectBack($params, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required']);
        }
        if ($params['resource'] !== '' && rtrim($params['resource'], '/') !== $base . $this->route) {
            $this->redirectBack($params, ['error' => 'invalid_target']);
        }
        if ($params['scope'] !== '' && self::filterScopes($params['scope']) === []) {
            // Every requested entry is unrecognized. Granting the unscoped
            // fallback would hand over MORE than asked — refuse instead.
            $this->redirectBack($params, ['error' => 'invalid_scope', 'error_description' => 'No requested scope is recognized; use api.* permission scopes or omit scope for full account access']);
        }

        $this->renderConsent($client, $params, null);
    }

    private function authorizeSubmit(): never
    {
        $params = $this->formParams($_POST);
        $ts = (int) ($_POST['ts'] ?? 0);

        if (!hash_equals($this->sign($params, $ts), (string) ($_POST['sig'] ?? '')) || time() > $ts) {
            $this->html(400, '<p>This authorization form has expired. Restart the connection from your MCP client.</p>');
        }

        // Re-validate against the store: the client could have been deleted
        // between rendering the form and submitting it.
        $client = $this->store->getClient($params['client_id']);
        if ($client === null || !in_array($params['redirect_uri'], $client['redirect_uris'], true)) {
            $this->html(400, '<p>Unknown client. Restart the connection from your MCP client.</p>');
        }

        if (isset($_POST['deny'])) {
            $this->redirectBack($params, ['error' => 'access_denied']);
        }

        // Same guard as the GET render: a signed form can only carry a scope
        // that passed it, but never let "all entries unrecognized" degenerate
        // into an unscoped (broader) grant.
        $requested = self::filterScopes($params['scope']);
        if ($params['scope'] !== '' && $requested === []) {
            $this->redirectBack($params, ['error' => 'invalid_scope']);
        }

        // A request that limits nothing mints an UNSCOPED key: empty scopes is
        // the only cap that still covers tools published by plugins installed
        // later (their permissions are outside our advertised api.* vocabulary,
        // so an explicit list can never reach them). This also normalizes the
        // wildcard away — '*' never reaches a key. The freeze box and the
        // per-scope checkboxes are the user's narrowing controls; unsigned like
        // the password and the deny button, and forging them can only narrow.
        // Nothing ticked is refused up front (before the password is spent),
        // not treated as Deny: a stray click shouldn't end the connection.
        $granted = self::resolveGrant($requested, self::tickedScopes() ?? [], isset($_POST['limit_scopes']));
        if ($granted === null) {
            $this->renderConsent($client, $params, 'Keep at least one permission ticked, or choose Deny.');
        }

        $login = trim((string) ($_POST['username'] ?? ''));

        // Lockout keys: the client address (Uri::ip() honours the site's trusted
        // proxy-header config; unknown IPs share one fail-closed 'UNKNOWN' bucket)
        // and the attempted username, so neither rotating usernames nor rotating
        // addresses resets the counter. The username is truncated so a hostile
        // client can't bloat the store with mile-long names.
        $throttleKeys = ['ip:' . Uri::ip()];
        if ($login !== '') {
            $throttleKeys[] = 'user:' . mb_substr(mb_strtolower($login), 0, 64);
        }
        foreach ($throttleKeys as $key) {
            if ($this->store->failureCount($key) >= self::MAX_FAILURES) {
                $this->renderConsent($client, $params, 'Too many failed attempts. Try again in a few minutes.');
            }
        }

        // find() matches username OR email, the same lookup Grav's own login uses.
        $user = $login !== '' ? $this->grav['accounts']->find($login, ['username', 'email']) : null;
        $ok = $user !== null
            && $user->exists()
            && (string) $user->get('state', 'enabled') === 'enabled'
            && $user->authenticate((string) ($_POST['password'] ?? ''));

        if (!$ok) {
            $this->recordLoginFailure($throttleKeys);
            // The fixed delay stays as a per-request cost on top of the counter.
            usleep(500000);
            $this->renderConsent($client, $params, 'Invalid credentials.');
        }

        // A TOTP-enrolled account must present a code — the consent form must not be
        // a 2FA bypass. Verification reuses the Login plugin's TwoFactorAuth (the
        // same RobThree TOTP the admin login uses); if that plugin is missing we
        // fail closed rather than skip the second factor.
        $totpSecret = str_replace(' ', '', (string) $user->get('twofa_secret', ''));
        if (!empty($user->get('twofa_enabled')) && $totpSecret !== '') {
            if (!class_exists(\Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth::class)) {
                $this->renderConsent($client, $params, 'This account requires two-factor authentication, but the Login plugin is not available to verify codes.');
            }
            $code = preg_replace('/\s+/', '', (string) ($_POST['twofa'] ?? ''));
            if ($code === '' || !(new \Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth())->verifyCode($totpSecret, $code)) {
                $this->recordLoginFailure($throttleKeys);
                usleep(500000);
                $this->renderConsent($client, $params, 'Invalid two-factor code.');
            }
        }

        // Distinct message: the password was right, the account just can't approve.
        $permission = (string) $this->grav['config']->get('plugins.mcp-server.oauth.require_permission', 'api.access');
        if (!self::accountMayConsent($user, $permission)) {
            $this->renderConsent($client, $params, 'That account lacks the "' . $permission . '" permission needed to approve MCP access.');
        }

        $this->store->clearFailures(...$throttleKeys);

        // The code is bound to the resolved account, not to whatever was typed.
        $username = (string) $user->get('username');

        $code = bin2hex(random_bytes(32));
        $this->store->putCode(hash('sha256', $code), [
            'client_id' => $params['client_id'],
            'username' => $username,
            'redirect_uri' => $params['redirect_uri'],
            'challenge' => $params['code_challenge'],
            // The granted (recognized) scopes, normalized — what the consent
            // screen displayed, what the key will carry.
            'scope' => implode(' ', $granted),
            'expires' => time() + self::CODE_TTL,
        ]);

        $this->log('info', sprintf(
            'consent approved: user "%s" granted %s to client "%s" (%s) from %s',
            $username,
            $granted === [] ? 'full account access' : 'scopes "' . implode(' ', $granted) . '"',
            (string) ($client['client_name'] ?? 'MCP client'),
            (string) (parse_url($params['redirect_uri'], PHP_URL_HOST) ?? ''),
            Uri::ip(),
        ));

        $this->redirectBack($params, ['code' => $code]);
    }

    // --- Token endpoint ---

    private function token(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->json(405, ['error' => 'invalid_request']);
        }

        match ((string) ($_POST['grant_type'] ?? '')) {
            'authorization_code' => $this->tokenFromCode(),
            'refresh_token' => $this->tokenFromRefresh(),
            default => $this->json(400, ['error' => 'unsupported_grant_type']),
        };
    }

    private function tokenFromCode(): never
    {
        $code = $this->store->takeCode(hash('sha256', (string) ($_POST['code'] ?? '')));

        if ($code === null
            || (int) $code['expires'] < time()
            || $code['client_id'] !== (string) ($_POST['client_id'] ?? '')
            || $code['redirect_uri'] !== (string) ($_POST['redirect_uri'] ?? '')
            || !self::verifyPkce((string) ($_POST['code_verifier'] ?? ''), (string) $code['challenge'])) {
            $this->json(400, ['error' => 'invalid_grant']);
        }

        $this->issueTokens($code['client_id'], $code['username'], (string) ($code['scope'] ?? ''));
    }

    private function tokenFromRefresh(): never
    {
        $token = $this->store->takeRefresh(hash('sha256', (string) ($_POST['refresh_token'] ?? '')));

        if ($token === null || (int) $token['expires'] < time()) {
            $this->json(400, ['error' => 'invalid_grant']);
        }

        if (!empty($token['used'])) {
            // A replayed refresh token means two parties hold this grant and
            // one of them is a thief (OAuth 2.1 treats rotation reuse exactly
            // so). Revoke the whole family — every live descendant refresh
            // token and its access key — instead of leaving the successor
            // alive in unknown hands.
            $keyIds = $this->store->revokeFamily((string) ($token['family'] ?? ''));
            $user = $this->grav['accounts']->load((string) $token['username']);
            if ($user->exists()) {
                $manager = new ApiKeyManager();
                foreach ($keyIds as $keyId) {
                    $manager->revokeKey($user, $keyId);
                }
            }
            $this->log('warning', sprintf(
                'refresh token replay for user "%s" (client %s): revoked %d descendant token(s) as stolen',
                (string) $token['username'],
                (string) $token['client_id'],
                count($keyIds),
            ));
            $this->json(400, ['error' => 'invalid_grant']);
        }

        if ($token['client_id'] !== (string) ($_POST['client_id'] ?? '')) {
            $this->json(400, ['error' => 'invalid_grant']);
        }

        // Rotate: revoke the old access key, then reissue both tokens.
        $user = $this->grav['accounts']->load((string) $token['username']);
        if ($user->exists() && !empty($token['key_id'])) {
            (new ApiKeyManager())->revokeKey($user, (string) $token['key_id']);
        }

        $this->issueTokens((string) $token['client_id'], (string) $token['username'], (string) ($token['scope'] ?? ''), (string) ($token['family'] ?? ''));
    }

    private function issueTokens(string $clientId, string $username, string $scope, string $family = ''): never
    {
        $user = $this->grav['accounts']->load($username);
        if (!$user->exists() || (string) $user->get('state', 'enabled') !== 'enabled') {
            $this->json(400, ['error' => 'invalid_grant']);
        }

        $client = $this->store->getClient($clientId);
        $accessDays = max(1, (int) $this->grav['config']->get('plugins.mcp-server.oauth.access_token_days', 7));
        $refreshDays = max(1, (int) $this->grav['config']->get('plugins.mcp-server.oauth.refresh_token_days', 90));

        // The key carries the granted scopes as its cap; effective access is
        // always this cap intersected with the account's live permissions,
        // enforced by the api plugin on every request. Empty = unscoped.
        $scopes = self::filterScopes($scope);

        $key = (new ApiKeyManager())->generateKey(
            $user,
            sprintf('MCP OAuth: %s (%s)', $client['client_name'] ?? 'MCP client', substr($clientId, 0, 8)),
            $scopes,
            $accessDays,
        );

        $refresh = bin2hex(random_bytes(32));
        $this->store->putRefresh(hash('sha256', $refresh), [
            'client_id' => $clientId,
            'username' => $username,
            'key_id' => $key['id'],
            'scope' => implode(' ', $scopes),
            // The rotation lineage: replay of any used ancestor revokes the
            // whole family. Empty on pre-family tokens — they start one here.
            'family' => $family !== '' ? $family : bin2hex(random_bytes(8)),
            'expires' => time() + $refreshDays * 86400,
        ]);

        // RFC 6749 §5.1: scope is REQUIRED when it differs from the request —
        // filtering means it can, so always say what was actually granted.
        $this->json(200, [
            'access_token' => $key['key'],
            'token_type' => 'Bearer',
            'expires_in' => $accessDays * 86400,
            'refresh_token' => $refresh,
        ] + ($scopes !== [] ? ['scope' => implode(' ', $scopes)] : []));
    }

    // --- Revocation endpoint (RFC 7009) ---

    /**
     * Possession is authority here: revocation only destroys access, so any
     * caller presenting a valid token may kill it — no client_id binding, and
     * token_type_hint is ignored because the two token shapes are distinguishable.
     * Unknown tokens still get 200 per RFC 7009 §2.2 (no validity oracle).
     */
    private function revoke(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->json(405, ['error' => 'invalid_request']);
        }

        $token = (string) ($_POST['token'] ?? '');
        if ($token === '') {
            $this->json(400, ['error' => 'invalid_request', 'error_description' => 'token is required']);
        }

        $refresh = $this->store->takeRefresh(hash('sha256', $token));
        if ($refresh !== null && !empty($refresh['key_id'])) {
            // A dead refresh token takes its access key with it.
            $user = $this->grav['accounts']->load((string) $refresh['username']);
            if ($user->exists()) {
                (new ApiKeyManager())->revokeKey($user, (string) $refresh['key_id']);
            }
            // ...and any sibling refresh tokens on the same key die too.
            $this->store->deleteRefreshByKeyId((string) $refresh['key_id']);
        } elseif (str_starts_with($token, 'grav_')) {
            $found = (new ApiKeyManager())->findKey($token);
            if ($found !== null) {
                $user = $this->grav['accounts']->load((string) $found['username']);
                if ($user->exists()) {
                    (new ApiKeyManager())->revokeKey($user, (string) $found['key_id']);
                }
                // ...and a dead access key takes its refresh token with it.
                $this->store->deleteRefreshByKeyId((string) $found['key_id']);
            }
        }

        http_response_code(200);
        exit;
    }

    // --- Helpers ---

    /**
     * Security events into grav.log: a consent turning a password into a
     * 90-day key, a lockout, a replayed refresh token — the trail an admin
     * reads after a stolen password. Soft: bare-container tests have no log
     * service, and losing a log line must never fail the flow itself.
     */
    private function log(string $level, string $message): void
    {
        if (isset($this->grav['log'])) {
            $this->grav['log']->{$level}('mcp-server oauth: ' . $message);
        }
    }

    /** Count a failed consent login on every throttle key; log the crossing into lockout. */
    private function recordLoginFailure(array $throttleKeys): void
    {
        foreach ($throttleKeys as $key) {
            $this->store->recordFailure($key, self::LOCKOUT_TTL);
            // === not >=: the count gate at the top of authorizeSubmit() blocks
            // further attempts, so each window crosses MAX_FAILURES exactly once.
            if ($this->store->failureCount($key) === self::MAX_FAILURES) {
                $this->log('warning', sprintf('consent lockout: "%s" locked for %d minutes after %d failed logins (last from %s)', $key, intdiv(self::LOCKOUT_TTL, 60), self::MAX_FAILURES, Uri::ip()));
            }
        }
    }

    /**
     * The scopes[] checkboxes as posted. Every box is ticked on the first
     * render, so only a POST (a real submission or a failed-login re-render)
     * can mean "unticked" — null says "no submission yet, tick everything".
     *
     * @return list<string>|null
     */
    private static function tickedScopes(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return null;
        }

        return array_values(array_filter((array) ($_POST['scopes'] ?? []), 'is_string'));
    }

    /** @return array<string, string> the OAuth params carried through the consent form */
    private function formParams(array $source): array
    {
        $params = [];
        foreach (self::FORM_PARAMS as $k) {
            $params[$k] = (string) ($source[$k] ?? '');
        }
        return $params;
    }

    /** HMAC over the form params, keyed on Grav's security salt. */
    private function sign(array $params, int $ts): string
    {
        $salt = (string) $this->grav['config']->get('security.salt');
        return hash_hmac('sha256', $ts . "\n" . json_encode($this->formParams($params)), $salt);
    }

    private function renderConsent(array $client, array $params, ?string $error): never
    {
        $ts = time() + self::FORM_TTL;
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);

        $site = $e($this->grav['config']->get('site.title', 'Grav'));
        $name = $e($client['client_name'] ?? 'MCP client');
        $host = $e((string) (parse_url($params['redirect_uri'], PHP_URL_HOST) ?? ''));
        $errorHtml = $error !== null ? '<p class="error">' . $e($error) . '</p>' : '';

        // Show what approval hands over: the granted (recognized) scopes — the
        // same list the minted key will carry. A request that limits nothing
        // (none, wildcard, or the whole advertised vocabulary — claude.ai
        // requests everything scopes_supported lists) is named for what it is
        // instead of dressed up as a 20-item "limitation".
        // Every offered scope is a checkbox (issue #1): untick to exclude it
        // from the grant. All ticked on first render; a re-render (failed login)
        // keeps the user's selection, or a mistyped password would silently
        // widen the grant.
        $scopes = self::filterScopes($params['scope']);
        $ticked = self::tickedScopes();
        $li = static fn(string $s): string => '<li><label><input type="checkbox" name="scopes[]" value="' . $e($s) . '"'
            . ($ticked === null || in_array($s, $ticked, true) ? ' checked' : '')
            . '> <code>' . $e($s) . '</code></label></li>';
        $limitOption = '';
        if (self::limitsNothing($scopes)) {
            // Once the user unticks something (visible only on a re-render), the
            // grant is an explicit cap and the headline must say so — the same
            // screen must not promise full access above a narrowed list.
            $supported = self::supportedScopes();
            $narrowed = $ticked !== null && count(array_intersect($supported, $ticked)) < count($supported);
            $grants = $narrowed
                ? '<p>Approving grants access <strong>limited to the permissions ticked below</strong> — you unticked some of what the client asked for, so tools that plugins add later are excluded too.</p>'
                : '<p>Approving grants <strong>full account access</strong> — '
                    . ($scopes === [] ? 'the client did not request any limiting scopes.' : 'the client requested every available scope, so the request limits nothing.')
                    . ' The connection can do anything this account can, <strong>including tools that plugins add later</strong>.</p>';
            // The expansion is the advertised vocabulary, collapsed by default:
            // the point of this branch is that the list is not a limitation,
            // so it's there for the curious, not in everyone's way. Titled as a
            // snapshot, because the grant is not limited to it — until the user
            // unticks something, which keeps the list open so the cap stays visible.
            if ($supported !== []) {
                $grants .= '<details' . ($narrowed ? ' open' : '') . '><summary>What that currently includes</summary>'
                    . '<p class="cap">Untick a permission to exclude it from this connection.</p><ul class="scopes">'
                    . implode('', array_map($li, $supported))
                    . '</ul></details>';
                // Opt-out, so the default keeps working as tools appear.
                $limitOption = '<label class="limit"><input type="checkbox" name="limit_scopes" value="1"'
                    . (isset($_POST['limit_scopes']) ? ' checked' : '')
                    . '> Limit this connection to only the permissions ticked above (tools added by future plugin updates will be excluded)</label>';
            }
        } else {
            $grants = '<p>Approving grants access limited to:</p><ul class="scopes">'
                . implode('', array_map($li, $scopes))
                . '</ul><p class="cap">Untick a permission to exclude it from this connection.</p>';
        }
        $grants .= '<p class="cap">Whatever you approve is capped by the account you sign in with: the connector can never do more than that account\'s own permissions allow.</p>';

        $hidden = '';
        foreach ($this->formParams($params) as $k => $v) {
            $hidden .= '<input type="hidden" name="' . $k . '" value="' . $e($v) . '">';
        }
        $hidden .= '<input type="hidden" name="ts" value="' . $ts . '">';
        $hidden .= '<input type="hidden" name="sig" value="' . $e($this->sign($params, $ts)) . '">';

        $this->html(200, <<<HTML
            <h1>{$site}</h1>
            <p><strong>{$name}</strong> ({$host}) is requesting MCP access to this site.</p>
            <form method="post" autocomplete="off">
              {$hidden}
              {$grants}
              <p>Sign in with your Grav account to approve.</p>
              {$errorHtml}
              <label>Username <input type="text" name="username" required autofocus></label>
              <label>Password <input type="password" name="password" required></label>
              <label>Two-factor code <input type="text" name="twofa" inputmode="numeric" autocomplete="one-time-code" placeholder="if enabled on your account"></label>
              {$limitOption}
              <div class="buttons">
                <button type="submit">Approve</button>
                <button type="submit" name="deny" value="1" class="deny">Deny</button>
              </div>
            </form>
            HTML);
    }

    private function redirectBack(array $params, array $extra): never
    {
        // RFC 9207: name the issuer in every authorization response so clients
        // talking to several authorization servers can detect mix-ups. Must
        // match the discovery metadata's issuer exactly.
        $extra['iss'] = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        if (($params['state'] ?? '') !== '') {
            $extra['state'] = $params['state'];
        }
        $separator = str_contains($params['redirect_uri'], '?') ? '&' : '?';
        header('Location: ' . $params['redirect_uri'] . $separator . http_build_query($extra), true, 302);
        exit;
    }

    private function json(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        // RFC 6749 §5.1: token responses carry credentials — never cacheable.
        // Blanket no-store: everything else served here is trivially regenerated.
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function html(int $status, string $body): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        // RFC 6749 §10.13: the consent page must never render inside a frame.
        header('X-Frame-Options: DENY');
        $logo = self::LOGO;
        $favicon = 'data:image/svg+xml,' . rawurlencode(str_replace("\n", '', self::LOGO));
        echo <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex"><title>MCP Authorization</title>
            <link rel="icon" href="{$favicon}">
            <style>
              body{font-family:system-ui,sans-serif;max-width:24rem;margin:10vh auto;padding:0 1rem;color:#222}
              body>svg{display:block;width:3rem;height:3rem}
              h1{font-size:1.5rem;margin:.75rem 0 1rem}
              .cap{color:#555;font-size:.9rem}
              details{margin:.75rem 0}
              summary{cursor:pointer;color:#555;font-size:.9rem}
              label{display:block;margin:.75rem 0}
              .scopes{padding-left:1.25rem}
              .scopes label{display:inline;margin:0}
              .limit{color:#555;font-size:.9rem}
              input[type=text],input[type=password]{width:100%;padding:.5rem;box-sizing:border-box}
              .buttons{margin-top:1rem;display:flex;gap:.5rem}
              button{padding:.5rem 1.25rem;cursor:pointer}
              .deny{background:none;border:1px solid #999}
              .error{color:#b00020}
            </style>
            </head><body>{$logo}{$body}</body></html>
            HTML;
        exit;
    }
}
