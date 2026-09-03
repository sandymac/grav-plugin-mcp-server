<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

use Grav\Common\Grav;

/**
 * Minimal stateless MCP server over Streamable HTTP.
 *
 * One POST endpoint, one JSON-RPC message per request, plain JSON responses.
 * No SSE, no sessions, no batching — the MCP spec allows all three to be
 * absent for a stateless server (newer revisions have removed sessions and
 * batching from the protocol entirely), and mainstream clients (Claude Code,
 * MCP Inspector) work against exactly this.
 */
class McpServer
{
    /** Keep in step with blueprints.yaml — tests/smoke.php fails if they drift. */
    public const string VERSION = '1.2.3';

    /**
     * The commit a release zip was built from — git archive substitutes it
     * (export-subst in .gitattributes); a git checkout keeps the placeholder.
     */
    private const string BUILD = '$Format:%h$';

    /** Short commit hash of the release build, or null on a git checkout. */
    public static function build(): ?string
    {
        return str_starts_with(self::BUILD, '$') ? null : self::BUILD;
    }

    /** Matches the api dependency floor in blueprints.yaml — smoke asserts they agree. */
    public const string MIN_API_VERSION = '1.0.22';
    public const array SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26'];

    private ToolRegistry $tools;

    private Resources $resources;

    public function __construct(private readonly ?Grav $grav = null)
    {
        $this->tools = new ToolRegistry($grav);
        $this->resources = new Resources($grav);
    }

    /** Entry point from the plugin: emits an HTTP response and exits. */
    public function run(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            // Stateless: no SSE stream to GET, no session to DELETE.
            header('Allow: POST');
            $this->respond(405, $this->error(null, -32600, 'MCP endpoint accepts POST only'));
        }

        if (!$this->authenticate()) {
            // resource_metadata points OAuth-capable clients (claude.ai) at
            // the discovery document that starts their authorization flow.
            header($this->wwwAuthenticate());
            $this->respond(401, $this->error(null, -32001, 'Unauthorized: pass a Grav API key as "Authorization: Bearer grav_..." or connect via OAuth'));
        }

        $message = json_decode((string) file_get_contents('php://input'), true);

        if (!is_array($message) || array_is_list($message)) {
            $this->respond(400, $this->error(null, -32700, 'Expected a single JSON-RPC message object (batching not supported)'));
        }

        $response = $this->dispatch($message);

        if ($response === null) {
            http_response_code(202); // notification: acknowledged, no body
            $this->stripPlantedSessionCookie();
            exit;
        }

        $this->respond(200, $response);
    }

    /**
     * Handle one JSON-RPC message. Returns the response array, or null for
     * notifications. Pure protocol logic — no HTTP, no auth — so
     * tests/smoke.php can exercise it directly.
     */
    public function dispatch(array $message): ?array
    {
        if (!array_key_exists('id', $message)) {
            return null; // notification (e.g. notifications/initialized)
        }

        $id = $message['id'];
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);

        return match ($method) {
            'initialize' => $this->initialize($id, $params),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->tools->list()]),
            'tools/call' => $this->callTool($id, $params),
            'resources/list' => $this->result($id, ['resources' => $this->resources->list()]),
            'resources/read' => $this->readResource($id, $params),
            'prompts/list' => $this->result($id, ['prompts' => Prompts::list()]),
            'prompts/get' => $this->getPrompt($id, $params),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    private function initialize(mixed $id, array $params): array
    {
        // git-clone installs bypass GPM's dependency resolution, so an outdated
        // api plugin runs silently; warn once per handshake instead of never.
        if ($this->grav !== null) {
            $installed = ApiBridge::apiPluginVersion($this->grav);
            if ($installed !== null && version_compare($installed, self::MIN_API_VERSION, '<')) {
                $this->grav['log']->warning(sprintf(
                    'mcp-server plugin requires grav-plugin-api >= %s but %s is installed; tools for newer endpoints will fail with 404s',
                    self::MIN_API_VERSION,
                    $installed
                ));
            }
        }

        $access = $this->tools->toolAccess();

        return $this->result($id, [
            'protocolVersion' => $this->negotiateVersion((string) ($params['protocolVersion'] ?? '')),
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => ['name' => 'grav-plugin-mcp-server', 'version' => self::VERSION],
            'instructions' => 'The tool list is filtered to this account\'s permissions.'
                . ($access['hidden'] > 0 ? sprintf(' %d more tool%s exist behind permissions this account lacks.', $access['hidden'], $access['hidden'] === 1 ? '' : 's') : '')
                . ' Call whoami for the account\'s grants and what each missing permission would unlock.',
        ]);
    }

    private function callTool(mixed $id, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        if (!$this->tools->has($name)) {
            // Tool names are public (repo, README) — naming the missing
            // permission leaks nothing and turns a dead end into an ask.
            $missing = $this->tools->missingPermission($name);

            return $this->error($id, -32602, $missing === null
                ? "Unknown tool: {$name}"
                : "Tool '{$name}' exists but requires the {$missing} permission, which this account's credentials do not grant. Call whoami for the account's grants.");
        }

        try {
            return $this->result($id, $this->tools->call($name, (array) ($params['arguments'] ?? [])));
        } catch (\Throwable $e) {
            // Tool execution failures are results, not protocol errors (MCP spec).
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    private function readResource(mixed $id, array $params): array
    {
        $uri = (string) ($params['uri'] ?? '');
        $contents = $this->resources->read($uri);
        if ($contents === null) {
            return $this->error($id, -32002, "Resource not found: {$uri}");
        }

        return $this->result($id, ['contents' => $contents]);
    }

    private function getPrompt(mixed $id, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $prompt = Prompts::get($name, (array) ($params['arguments'] ?? []));
        if ($prompt === null) {
            return $this->error($id, -32602, "Unknown prompt: {$name}");
        }

        return $this->result($id, $prompt);
    }

    private function negotiateVersion(string $requested): string
    {
        return in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $requested
            : self::SUPPORTED_PROTOCOL_VERSIONS[0];
    }

    /**
     * Bearer auth against grav-plugin-api's key store. Fails closed: no Grav
     * container, no api plugin, or no valid key means no access.
     */
    private function authenticate(): bool
    {
        if ($this->grav === null) {
            return false;
        }

        if (!(bool) $this->grav['config']->get('plugins.mcp-server.require_auth', true)) {
            $this->tools->configure(null, []);
            $this->resources->configure(null);

            return true; // explicitly disabled — local dev only
        }

        if (!class_exists(\Grav\Plugin\Api\Auth\ApiKeyAuthenticator::class)) {
            return false; // api plugin not installed: fail closed
        }

        // ApiKeyAuthenticator only reads X-API-Key (Authorization: Bearer is
        // JWT-only in the api plugin), while MCP clients send Bearer — so map
        // the Bearer token onto the header it does read.
        $request = $this->grav['request'];
        $token = self::bearerToken((string) $request->getHeaderLine('Authorization'));
        if ($token !== null) {
            $request = $request->withHeader('X-API-Key', $token);
        }

        $authenticator = new \Grav\Plugin\Api\Auth\ApiKeyAuthenticator($this->grav);
        $user = $authenticator->authenticate($request);
        if ($user === null) {
            return false;
        }

        // Tools re-present this key to the api plugin, which authenticates it
        // again itself; the scopes and the account's resolved permissions only
        // filter what tools/list shows.
        $apiKey = $request->getHeaderLine('X-API-Key') ?: null;
        $this->tools->configure($apiKey, $authenticator->getAuthenticatedScopes(), $user);
        $this->resources->configure($apiKey);

        return true;
    }

    /** RFC 6750: extract the token from an Authorization header, or null. */
    public static function bearerToken(string $header): ?string
    {
        return stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : null;
    }

    private function wwwAuthenticate(): string
    {
        $header = 'WWW-Authenticate: Bearer realm="mcp"';

        if ($this->grav !== null && (bool) $this->grav['config']->get('plugins.mcp-server.oauth.enabled', true)) {
            $base = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
            $route = '/' . trim((string) $this->grav['config']->get('plugins.mcp-server.route', '/mcp'), '/');
            $header .= sprintf(', resource_metadata="%s/.well-known/oauth-protected-resource%s"', $base, $route);
        }

        return $header;
    }

    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        $this->stripPlantedSessionCookie();
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Stop a stateless MCP call from planting the shared front-end PHP
     * session cookie.
     *
     * This endpoint isn't under `/admin`, so it rides the front-end
     * `grav-site-*` session cookie (no `-admin` split). Bearer auth never
     * sends that cookie, so Grav still starts a fresh session during boot
     * and queues a `Set-Cookie` for it; emitted, that cookie overwrites the
     * session of a visitor logged in to the public site in the same browser
     * and boots them out, then repeats on every poll. Ported from
     * grav-plugin-api's `ApiRouter::protectSharedSession()` (admin2#79, #88).
     *
     * Every MCP call is bearer-only and stateless — there's no SSO hand-off
     * parking state in the session the way the api plugin's stateful
     * endpoints do, so unlike the source method there's no exemption to
     * carry over here.
     */
    private function stripPlantedSessionCookie(): void
    {
        if ($this->grav === null || headers_sent() || !isset($this->grav['session'])) {
            return;
        }

        $sessionName = $this->grav['session']->getName();
        if (!$sessionName || isset($_COOKIE[$sessionName])) {
            // No session name resolved, or the caller brought its own
            // session cookie — there is nothing freshly-minted to strip.
            return;
        }

        // Remove only the just-planted session cookie, preserving every
        // other Set-Cookie (CORS, etc.). Mirrors the header rewrite in
        // Grav\Framework\Session\Session::removeCookie(), which we can't
        // call (protected). The leading space matches "Set-Cookie: <name>=".
        $needle = " {$sessionName}=";
        $kept = [];
        $found = false;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            if (str_contains($header, $needle)) {
                $found = true;
            } else {
                $kept[] = $header;
            }
        }

        if (!$found) {
            return;
        }

        header_remove('Set-Cookie');
        foreach ($kept as $header) {
            header($header, false);
        }
    }
}
