<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * MCP tool descriptors and dispatch: the built-in site_info tool plus every
 * domain class in DOMAINS. Descriptors are static data — no Grav needed to
 * list them.
 */
class ToolRegistry
{
    private const array DOMAINS = [
        Tools\PagesTools::class,
        Tools\MultilingualTools::class,
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
    }

    /** @return list<array<string, mixed>> MCP tool descriptors */
    public function list(): array
    {
        $descriptors = [];
        foreach ($this->all() as $tool) {
            if ($this->visible($tool['permission'])) {
                $descriptors[] = $tool['descriptor'];
            }
        }

        return $descriptors;
    }

    public function has(string $name): bool
    {
        $tool = $this->all()[$name] ?? null;

        return $tool !== null && $this->visible($tool['permission']);
    }

    /** @return array<string, mixed> MCP CallToolResult */
    public function call(string $name, array $arguments): array
    {
        return ($this->all()[$name]['handler'])($this->bridge(), $arguments);
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
        if ($permission === null) {
            return true;
        }
        if ($this->scopes !== [] && !self::scopeAllows($this->scopes, $permission)) {
            return false;
        }

        return $this->accountHolds($permission);
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

        // api.super is authority everywhere in the api plugin; honour it here too.
        return $resolver->resolve($this->user, $permission) === true
            || $resolver->resolveExact($this->user, 'api.super') === true;
    }

    /**
     * The permission a caller lacks for an existing-but-hidden tool, or null
     * when the tool is unknown or already visible — lets tools/call distinguish
     * "no such tool" from "grant this permission and it appears".
     */
    public function missingPermission(string $name): ?string
    {
        $tool = $this->all()[$name] ?? null;
        if ($tool === null || $this->visible($tool['permission'])) {
            return null;
        }

        return $tool['permission'];
    }

    /** @return array{visible: int, hidden: int, hidden_by_missing_permission: array<string, list<string>>} */
    public function toolAccess(): array
    {
        $visible = 0;
        $hidden = [];
        foreach ($this->all() as $name => $tool) {
            if ($this->visible($tool['permission'])) {
                $visible++;
            } else {
                $hidden[$tool['permission']][] = $name;
            }
        }
        ksort($hidden);

        return [
            'visible' => $visible,
            'hidden' => array_sum(array_map('count', $hidden)),
            'hidden_by_missing_permission' => $hidden,
        ];
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

    private function bridge(): ApiBridge
    {
        return $this->bridge ??= new ApiBridge($this->grav, $this->apiKey);
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
                        'api_plugin_version' => $this->grav !== null ? ApiBridge::apiPluginVersion($this->grav) : null,
                    ]),
                    'descriptor' => [
                        'name' => 'site_info',
                        'description' => 'Basic information about this Grav site: title, Grav version, MCP plugin version.',
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
                        'description' => 'The account behind the current key or OAuth token: username, profile, and its resolved permissions — the grants that determine which tools are visible and callable (each tool description states its requirement). Also reports tool_access: how many tools are hidden from this account, grouped by the permission that would unlock them. [Requires: api.access]',
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
