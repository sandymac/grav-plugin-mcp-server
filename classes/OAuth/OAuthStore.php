<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\OAuth;

use Grav\Common\Grav;

/**
 * JSON-file store for OAuth state: registered clients, pending authorization
 * codes, refresh tokens, and consent-login failure counts. Codes and refresh
 * tokens are stored as SHA-256 hashes only; expired entries are pruned on
 * every save.
 * ponytail: single JSON file + atomic rename, same storage idiom as the API
 * plugin's ApiKeyManager; move to a real backend only if concurrency bites.
 */
class OAuthStore
{
    private array $data;

    public function __construct(private readonly string $file)
    {
        $this->data = is_file($this->file) ? ((array) json_decode((string) file_get_contents($this->file), true)) : [];
        $this->data += ['clients' => [], 'codes' => [], 'refresh_tokens' => [], 'failures' => []];
    }

    public static function forGrav(Grav $grav): self
    {
        return new self($grav['locator']->findResource('user://data', true, true) . '/mcp-server/oauth.json');
    }

    public function getClient(string $clientId): ?array
    {
        return $this->data['clients'][$clientId] ?? null;
    }

    public function putClient(array $client): void
    {
        // Registrations are the only growth path, so prune stale clients here.
        // A client stays while a live code or refresh token references it, or
        // its registration is under a day old (registered but not yet through
        // consent). Anything older is past the re-consent point anyway, and DCR
        // clients re-register as a matter of course.
        $now = time();
        $live = [];
        foreach ([$this->data['codes'], $this->data['refresh_tokens']] as $entries) {
            foreach ($entries as $entry) {
                if (is_array($entry) && ((int) ($entry['expires'] ?? 0)) > $now && isset($entry['client_id'])) {
                    $live[(string) $entry['client_id']] = true;
                }
            }
        }
        $cutoff = $now - 86400;
        $this->data['clients'] = array_filter(
            $this->data['clients'],
            static fn($c): bool => is_array($c)
                && (isset($live[(string) ($c['client_id'] ?? '')]) || (int) ($c['created'] ?? 0) > $cutoff),
        );

        $this->data['clients'][$client['client_id']] = $client;
        $this->save();
    }

    public function putCode(string $hash, array $code): void
    {
        $this->data['codes'][$hash] = $code;
        $this->save();
    }

    /** Fetch and delete: authorization codes are single-use. */
    public function takeCode(string $hash): ?array
    {
        $code = $this->data['codes'][$hash] ?? null;
        if ($code !== null) {
            unset($this->data['codes'][$hash]);
            $this->save();
        }
        return is_array($code) ? $code : null;
    }

    public function putRefresh(string $hash, array $token): void
    {
        $this->data['refresh_tokens'][$hash] = $token;
        $this->save();
    }

    /** Fetch and delete: refresh tokens rotate on every use. */
    public function takeRefresh(string $hash): ?array
    {
        $token = $this->data['refresh_tokens'][$hash] ?? null;
        if ($token !== null) {
            unset($this->data['refresh_tokens'][$hash]);
            $this->save();
        }
        return is_array($token) ? $token : null;
    }

    /** Consent-login failures for a throttle key ('ip:...' or 'user:...'), 0 once expired. */
    public function failureCount(string $key): int
    {
        $entry = $this->data['failures'][$key] ?? null;
        return is_array($entry) && ((int) ($entry['expires'] ?? 0)) > time() ? (int) ($entry['count'] ?? 0) : 0;
    }

    /** Sliding window: every failure bumps the count and pushes expiry out again. */
    public function recordFailure(string $key, int $ttl): void
    {
        $this->data['failures'][$key] = [
            'count' => $this->failureCount($key) + 1,
            'expires' => time() + $ttl,
        ];
        $this->save();
    }

    public function clearFailures(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->data['failures'][$key]);
        }
        $this->save();
    }

    /** Drop refresh tokens tied to a revoked access key (RFC 7009 cascade). */
    public function deleteRefreshByKeyId(string $keyId): void
    {
        // Non-array entries (a corrupted store) are dropped too — self-heal
        // instead of TypeError-ing revocation into a 500.
        $this->data['refresh_tokens'] = array_filter(
            $this->data['refresh_tokens'],
            static fn($t): bool => is_array($t) && (string) ($t['key_id'] ?? '') !== $keyId,
        );
        $this->save();
    }

    private function save(): void
    {
        $now = time();
        // Also drops non-array entries, so a corrupted store heals on save.
        $fresh = static fn($entry): bool => is_array($entry) && ((int) ($entry['expires'] ?? 0)) > $now;
        $this->data['codes'] = array_filter($this->data['codes'], $fresh);
        $this->data['refresh_tokens'] = array_filter($this->data['refresh_tokens'], $fresh);
        $this->data['failures'] = array_filter($this->data['failures'], $fresh);

        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
        }

        // A silent write failure would truncate single-use codes and refresh
        // tokens, so fail loudly and leave the previous file intact.
        $tmp = $this->file . '.tmp';
        if (file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
            || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Unable to write "%s"', $this->file));
        }
    }
}
