<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\McpServer\McpServer;
use Grav\Plugin\McpServer\OAuth\OAuthServer;

class McpServerPlugin extends Plugin
{
    private string $path = '';
    private string $mcpRoute = '';

    /** Markdown permission → tools table for the admin Permissions tab (blueprints.yaml data-content@). */
    public static function permissionsTable(): string
    {
        self::registerAutoload();

        $rows = '';
        try {
            foreach ((new \Grav\Plugin\McpServer\ToolRegistry(null))->permissionMap() as $permission => $tools) {
                $label = $permission === '' ? '*(none — always available)*' : '`' . $permission . '`';
                $rows .= '| ' . $label . ' | `' . implode('`, `', $tools) . '` |' . "\n";
            }
        } catch (\Throwable) {
            return 'The permission → tool table could not be generated here.';
        }

        return <<<MD
        A connected client only sees — and can only call — the tools its account's
        permissions (and its API key's scopes, if any) allow. Grant permissions under
        *Accounts → (user) → Access*; each `api.*` row below is a checkbox there.

        | Permission | Tools it gates |
        |---|---|
        {$rows}
        MD;
    }

    /** Markdown for the admin "Connecting a client" notice (blueprints.yaml data-content@). */
    public static function connectNotice(): string
    {
        $keysHint = '';
        try {
            $grav = \Grav\Common\Grav::instance();
            $route = '/' . trim((string) $grav['config']->get('plugins.mcp-server.route', '/mcp'), '/');
            $base = rtrim((string) $grav['uri']->rootUrl(true), '/');
            $url = $base . $route;

            // The API Keys section on the user edit page is an Admin2 feature
            // (it consumes the api plugin's /users/{u}/api-keys endpoints), so
            // only mention — and link — it when Admin2 is enabled.
            if ((bool) $grav['config']->get('plugins.admin2.enabled', false)) {
                $adminBase = $base . '/' . trim((string) $grav['config']->get('plugins.admin2.route', '/admin2'), '/');
                $username = (string) ($grav['user']->username ?? '');
                $keysHint = 'Keys can also be created in the **API Keys** section at the bottom of each'
                    . " user's edit page"
                    . ($username !== '' ? sprintf(' ([open your account](%s/users/%s))', $adminBase, rawurlencode($username)) : '')
                    . '.';
            }
        } catch (\Throwable) {
            $url = 'https://yoursite/mcp';
            $keysHint = '';
        }

        return <<<MD
        **Connecting a client**

        **OAuth** — hosted connectors with an interactive sign-in need nothing set up
        here. Point one at `{$url}` and sign in on the consent screen; the client
        registers itself through the OAuth flow.

        **API key** — clients that send a static Bearer token (CLI tools, scripts,
        CI) need a key generated for the account whose permissions they should
        inherit; every client then takes the same two pieces:

        ```
        bin/plugin api keys:generate --user=<username> --name="MCP Client"

        endpoint:  {$url}
        header:    Authorization: Bearer grav_...
        ```

        {$keysHint}

        A key can do exactly what that account's `api.*` permissions allow. List or
        revoke keys with `bin/plugin api keys:list` / `keys:revoke`, or ask an
        already-connected MCP client to run the `manage_api_keys` tool.

        **Limiting what the AI can do** — don't hand an assistant your own account's
        power. Create a separate account (e.g. `ai-bot`) holding only the
        permissions it should exercise, and connect the client with a key from (or an
        OAuth sign-in as) that account: a credential can never do more than its
        account allows, whatever the client asks for. Grant the bot **API Access**
        (also the default permission required to approve OAuth consent) plus just the
        `api.*` permissions it needs — the **Tool Access** tab shows which tools each
        permission unlocks. Leave the admin and site login permissions off and the
        account can use the API but never sign in anywhere else.
        MD;
    }
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 1000],
            ],
        ];
    }

    public function autoload(): void
    {
        self::registerAutoload();
    }

    /**
     * Idempotent, and deliberately reachable from the blueprint statics: Grav
     * evaluates data-content@ callables in contexts where the plugin's
     * autoload event never fires — `bin/gpm` on the CLI, or the plugin
     * installed but disabled — and GPM's package enumeration must not fatal
     * on an unloadable class.
     */
    private static function registerAutoload(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (is_file(__DIR__ . '/vendor/autoload.php')) {
            require __DIR__ . '/vendor/autoload.php';
            return;
        }

        // ponytail: spl fallback so a straight git clone into user/plugins
        // works without running composer; switch to composer-only if we ever
        // grow a real dependency.
        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\McpServer\\';
            if (str_starts_with($class, $prefix)) {
                $path = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            }
        });
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->mcpRoute = '/' . trim((string) $this->config->get('plugins.mcp-server.route', '/mcp'), '/');
        $this->path = rtrim((string) $this->grav['uri']->path(), '/') ?: '/';

        if ($this->path === $this->mcpRoute || $this->isOauthPath()) {
            $this->enable([
                'onPagesInitialized' => ['serveMcp', 100000],
            ]);
        }
    }

    private function isOauthPath(): bool
    {
        if (!(bool) $this->config->get('plugins.mcp-server.oauth.enabled', true)) {
            return false;
        }

        return str_starts_with($this->path, $this->mcpRoute . '/oauth/')
            || in_array($this->path, [
                '/.well-known/oauth-authorization-server',
                '/.well-known/oauth-authorization-server' . $this->mcpRoute,
                '/.well-known/oauth-protected-resource',
                '/.well-known/oauth-protected-resource' . $this->mcpRoute,
            ], true);
    }

    /**
     * Handles the MCP or OAuth request and terminates; never falls through to
     * page rendering.
     */
    public function serveMcp(): void
    {
        if ($this->path === $this->mcpRoute) {
            (new McpServer($this->grav))->run();
        }

        (new OAuthServer($this->grav, $this->mcpRoute))->handle($this->path);
    }
}
