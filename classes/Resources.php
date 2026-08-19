<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer;

use Grav\Common\Grav;

/**
 * The 5 static MCP resources (system-info, user-permissions, languages,
 * templates, taxonomy). Each maps onto one grav-plugin-api GET endpoint via
 * ApiBridge. A read never fails at the protocol level — any
 * failure (auth, permission, exception) returns a fixed JSON error payload as
 * the resource content instead.
 */
class Resources
{
    /** @var array<string, array{name:string, description:string, path:string, error:string}> */
    private const array MAP = [
        'grav://system/info' => [
            'name' => 'system-info',
            'description' => 'Grav CMS system information: version, PHP version, environment, disk usage, installed packages',
            'path' => '/system/info',
            'error' => '{"error":"Failed to fetch system info. Check permissions (api.system.read)."}',
        ],
        'grav://user/permissions' => [
            'name' => 'user-permissions',
            'description' => "Current API user's resolved permission map — shows what operations this API key can perform",
            'path' => '/me',
            'error' => '{"error":"Failed to fetch permissions."}',
        ],
        'grav://languages' => [
            'name' => 'languages',
            'description' => 'Configured site languages with codes, names, and default language',
            'path' => '/languages',
            'error' => '{"error":"Failed to fetch languages."}',
        ],
        'grav://templates' => [
            'name' => 'templates',
            'description' => 'Available page templates/blueprints — the types of pages that can be created',
            'path' => '/blueprints/pages',
            'error' => '{"error":"Failed to fetch templates."}',
        ],
        'grav://taxonomy' => [
            'name' => 'taxonomy',
            'description' => 'All taxonomy types (e.g. category, tag) and their current values used across the site',
            'path' => '/taxonomy',
            'error' => '{"error":"Failed to fetch taxonomy."}',
        ],
    ];

    private ?string $apiKey = null;

    private ?ApiBridge $bridge = null;

    public function __construct(private readonly ?Grav $grav = null)
    {
    }

    /** Credentials for resource reads, from McpServer once the caller is authenticated. */
    public function configure(#[\SensitiveParameter] ?string $apiKey): void
    {
        $this->apiKey = $apiKey;
        $this->bridge = null;
    }

    /** @return list<array<string, mixed>> MCP resource descriptors */
    public function list(): array
    {
        $descriptors = [];
        foreach (self::MAP as $uri => $r) {
            $descriptors[] = [
                'uri' => $uri,
                'name' => $r['name'],
                'description' => $r['description'],
                'mimeType' => 'application/json',
            ];
        }

        return $descriptors;
    }

    /** @return list<array<string, mixed>>|null resources/read `contents`; null for an unknown URI */
    public function read(string $uri): ?array
    {
        $r = self::MAP[$uri] ?? null;
        if ($r === null) {
            return null;
        }

        try {
            $resp = $this->bridge()->request('GET', $r['path']);
            $text = $resp['status'] >= 400
                ? $r['error']
                : $this->encode($uri, is_array($resp['json']) ? ($resp['json']['data'] ?? null) : null);
        } catch (\Throwable) {
            $text = $r['error'];
        }

        return [['uri' => $uri, 'mimeType' => 'application/json', 'text' => $text]];
    }

    private function encode(string $uri, mixed $data): string
    {
        // user-permissions exposes only a subset of /me's UserProfile.
        if ($uri === 'grav://user/permissions' && is_array($data)) {
            $data = [
                'username' => $data['username'] ?? null,
                'super_admin' => $data['super_admin'] ?? null,
                'access' => $data['access'] ?? null,
                'groups' => $data['groups'] ?? null,
            ];
        }

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function bridge(): ApiBridge
    {
        return $this->bridge ??= new ApiBridge($this->grav, $this->apiKey);
    }
}
