<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * MCP tool descriptors and dispatch: the built-in site_info tool plus every
 * domain class in DOMAINS. Core descriptors are static data — no Grav needed to
 * list them; the tools other plugins publish (PluginTools) need one, and are
 * simply absent without it.
 */
class ToolRegistry
{
    private const array DOMAINS = [
        Tools\PagesTools::class,
        Tools\MultilingualTools::class,
        Tools\TranslationsTools::class,
        Tools\MediaTools::class,
        Tools\ConfigTools::class,
        Tools\UsersTools::class,
        Tools\GpmTools::class,
        Tools\SystemTools::class,
        Tools\WebhooksTools::class,
        Tools\BlueprintsTools::class,
        Tools\PluginsTools::class,
    ];

    private ?string $apiKey = null;

    /** @var list<string> API-key scopes; empty means unscoped. */
    private array $scopes = [];

    /** The authenticated account, when known — visibility mirrors its permissions. */
    private ?UserInterface $user = null;

    private ?\Grav\Plugin\Api\PermissionResolver $resolver = null;

    private ?ApiBridge $bridge = null;

    /** @var array<string, array{descriptor: array, permission: ?string, handler: callable}>|null */
    private ?array $tools = null;

    /** @var array<string, array{descriptor: array, permission: ?string, handler: callable}>|null plugin-published tools for this caller. */
    private ?array $pluginTools = null;

    public function __construct(private readonly ?Grav $grav = null)
    {
    }

    /** Credentials for tool dispatch, from McpServer once the caller is authenticated. */
    public function configure(#[\SensitiveParameter] ?string $apiKey, array $scopes, ?UserInterface $user = null): void
    {
        $this->apiKey = $apiKey;
        $this->scopes = array_values($scopes);
        $this->user = $user;
        $this->bridge = null;
        $this->pluginTools = null; // /mcp/tools is filtered per caller
    }

    /** @return list<array<string, mixed>> MCP tool descriptors */
    public function list(): array
    {
        $descriptors = [];
        foreach ($this->all() + $this->pluginTools() as $tool) {
            if ($this->visible($tool['permission'])) {
                $descriptors[] = $tool['descriptor'];
            }
        }

        return $descriptors;
    }

    public function has(string $name): bool
    {
        $tool = $this->tool($name);

        return $tool !== null && $this->visible($tool['permission']);
    }

    /** @return array<string, mixed> MCP CallToolResult */
    public function call(string $name, array $arguments): array
    {
        // Unknown is unreachable (McpServer gates on has()); a throw here lands
        // in its catch as an isError result rather than a fatal.
        $handler = $this->tool($name)['handler'] ?? throw new \RuntimeException("Unknown tool: {$name}");

        return $handler($this->bridge(), $arguments);
    }

    /**
     * Scope-cap visibility (UX only — enforcement stays in the api plugin's
     * requirePermission()). An unscoped key sees everything its account can use.
     */
    public static function scopeAllows(array $scopes, string $permission): bool
    {
        foreach ($scopes as $scope) {
            if ($scope === '*' || $scope === $permission || str_starts_with($permission, $scope . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective surface is the intersection of the key's scopes and the
     * owning account's resolved permissions — the same pair the api plugin
     * enforces at call time, so what tools/list shows is what tools/call allows.
     */
    private function visible(?string $permission): bool
    {
        return $permission === null || $this->blocker($permission) === null;
    }

    /**
     * Which gate hides a permission: 'key_scope' when the key's scope list
     * excludes it (the account may well hold it — a scoped key is never widened
     * silently, DECISIONS.md #6), 'account' when the account lacks it, null when
     * neither does. The two have different remedies, so callers name the gate.
     */
    private function blocker(string $permission): ?string
    {
        if ($this->scopes !== [] && !self::scopeAllows($this->scopes, $permission)) {
            return 'key_scope';
        }

        return $this->accountHolds($permission) ? null : 'account';
    }

    /**
     * PermissionResolver, never $user->authorize() (which needs a login
     * session — see OAuthServer::accountMayConsent for the full rationale).
     * No user or no api plugin (bare protocol tests) means no account filter.
     */
    private function accountHolds(string $permission): bool
    {
        if ($this->user === null || !class_exists(\Grav\Plugin\Api\PermissionResolver::class)) {
            return true;
        }

        $resolver = $this->resolver ??= new \Grav\Plugin\Api\PermissionResolver();

        // Super is an explicit tier, never inherited from a blanket `admin: true`
        // or `api: true` grant (api 1.0.36+ hasPermission()); everything else
        // inherits. api.super is authority everywhere in the api plugin; honour
        // it here too.
        $held = $permission === 'admin.super' || $permission === 'api.super'
            ? $resolver->resolveExact($this->user, $permission)
            : $resolver->resolve($this->user, $permission);

        return $held === true || $resolver->resolveExact($this->user, 'api.super') === true;
    }

    /**
     * The permission a caller lacks for an existing-but-hidden tool, or null
     * when the tool is unknown or already visible — lets tools/call distinguish
     * "no such tool" from "grant this permission and it appears".
     */
    public function missingPermission(string $name): ?string
    {
        $tool = $this->tool($name);
        if ($tool === null || $this->visible($tool['permission'])) {
            return null;
        }

        return $tool['permission'];
    }

    /**
     * Why an existing tool is hidden: 'key_scope' or 'account' (see blocker()),
     * null when the tool is unknown or visible.
     */
    public function hiddenCause(string $name): ?string
    {
        $permission = $this->tool($name)['permission'] ?? null;

        return $permission === null ? null : $this->blocker($permission);
    }

    /**
     * Core tools plus the plugin tools /mcp/tools published to this caller.
     * The api plugin has already dropped plugin tools the account's permissions
     * exclude, so those never reach us and cannot be counted; a plugin tool the
     * KEY's scope list excludes does reach us and is reported. Hidden tools are
     * split by the gate that hides them because the remedies differ: a
     * key-scope gap is closed by re-consenting (the account may already hold
     * the permission), an account gap by granting it.
     *
     * @return array{visible: int, hidden: int, core_tools: int, plugin_tools: int, key_scopes: list<string>, hidden_by_key_scope: array<string, list<string>>, hidden_by_account_permission: array<string, list<string>>, note?: string}
     */
    public function toolAccess(): array
    {
        $visible = 0;
        $byScope = [];
        $byAccount = [];
        $plugin = $this->pluginTools();
        foreach ($this->all() + $plugin as $name => $tool) {
            $permission = $tool['permission'];
            $cause = $permission === null ? null : $this->blocker($permission);
            if ($cause === null) {
                $visible++;
            } elseif ($cause === 'key_scope') {
                $byScope[$permission][] = $name;
            } else {
                $byAccount[$permission][] = $name;
            }
        }
        ksort($byScope);
        ksort($byAccount);

        $access = [
            'visible' => $visible,
            'hidden' => array_sum(array_map('count', $byScope)) + array_sum(array_map('count', $byAccount)),
            // core_tools is the fixed surface; plugin_tools is what plugins
            // published to this account (discover_plugins lists them by plugin).
            'core_tools' => count($this->all()),
            'plugin_tools' => count($plugin),
            // [] = unscoped: the account's permissions are the only cap.
            'key_scopes' => $this->scopes,
            'hidden_by_key_scope' => $byScope,
            'hidden_by_account_permission' => $byAccount,
        ];
        if ($byScope !== []) {
            $access['note'] = 'hidden_by_key_scope: this key\'s scope list was fixed when it was granted and is never widened silently, so these tools stay hidden even where the access map above says the account holds the permission. Reconnect the connector to re-run consent with the current scopes, or mint a key that includes them. hidden_by_account_permission: grant the permission to the account or one of its groups.';
        }

        return $access;
    }

    /** @return array<string, list<string>> permission => tool names; the no-permission tools keyed as ''. */
    public function permissionMap(): array
    {
        $map = [];
        foreach ($this->all() as $name => $tool) {
            $map[$tool['permission'] ?? ''][] = $name;
        }
        ksort($map);
        foreach ($map as &$names) {
            sort($names);
        }

        return $map;
    }

    // protected so tests/plugin-tools.php can substitute a recording bridge.
    protected function bridge(): ApiBridge
    {
        return $this->bridge ??= new ApiBridge($this->grav, $this->apiKey);
    }

    /** A core tool, or a plugin tool from the manifest endpoint. */
    private function tool(string $name): ?array
    {
        return $this->all()[$name] ?? $this->pluginTools()[$name] ?? null;
    }

    /**
     * Tools other plugins publish, fetched once per caller. Core names win a
     * collision, so this can only ever add to the surface.
     *
     * @return array<string, array{descriptor: array, permission: ?string, handler: callable}>
     */
    private function pluginTools(): array
    {
        if ($this->pluginTools === null) {
            $data = PluginTools::fetch($this->bridge(), $this->grav);
            $this->pluginTools = $data === null ? [] : PluginTools::tools($data, array_keys($this->all()));
        }

        return $this->pluginTools;
    }

    /** @return array<string, array{descriptor: array, permission: ?string, handler: callable}> */
    private function all(): array
    {
        return $this->tools ??= array_merge(
            [
                'site_info' => [
                    'permission' => null,
                    'handler' => fn(ApiBridge $api, array $args): array => ApiBridge::toolJson([
                        'title' => $this->grav?->offsetGet('config')?->get('site.title'),
                        'grav_version' => \defined('GRAV_VERSION') ? \GRAV_VERSION : null,
                        'mcp_plugin_version' => McpServer::VERSION,
                        'mcp_plugin_build' => McpServer::build(),
                        'api_plugin_version' => $this->grav !== null ? ApiBridge::apiPluginVersion($this->grav) : null,
                        // Grav 2.1+: null where the key does not exist (older Grav), so a
                        // client can tell "off" from "not available".
                        'markdown_output' => $this->grav?->offsetGet('config')?->get('system.pages.markdown_output.enabled'),
                    ]),
                    'descriptor' => [
                        'name' => 'site_info',
                        'description' => 'Basic information about this Grav site: title, Grav version, MCP plugin version, api plugin version, and markdown_output — whether the Grav 2.1 Markdown output feature is on (any page can then be read as rendered Markdown at its URL plus ".md"; null when this Grav predates the feature).',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                // Lives here rather than in a domain class because tool_access
                // needs the registry itself — tool visibility is this plugin's
                // own data, so appending it is not an api-response transform.
                'whoami' => [
                    'permission' => 'api.access',
                    'descriptor' => [
                        'name' => 'whoami',
                        'title' => 'Who Am I',
                        'description' => 'The account behind the current key or OAuth token: username, profile, and its resolved permissions — the grants that determine which tools are visible and callable (each tool description states its requirement). Also reports tool_access: the key\'s scope list and the hidden tools split by what hides them — hidden_by_key_scope (the key was granted without that scope, even if the account holds the permission; reconnect the connector to re-consent) and hidden_by_account_permission (grant the permission to the account). [Requires: api.access]',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => new \stdClass(),
                            'additionalProperties' => false,
                        ],
                        'annotations' => ['readOnlyHint' => true],
                    ],
                    'handler' => fn(ApiBridge $api, array $args): array => ApiBridge::fromResponse(
                        $api->request('GET', '/me'),
                        transform: fn(mixed $data): array => (is_array($data) ? $data : ['me' => $data])
                            + ['tool_access' => $this->toolAccess()]
                    ),
                ],
            ],
            ...array_map(static fn(string $domain): array => $domain::tools(), self::DOMAINS)
        );
    }
}
