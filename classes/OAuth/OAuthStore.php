<?php

declare(strict_types=1);

namespace Grav\Plugin\McpServer\OAuth;

use Grav\Common\Grav;

/**
 * JSON-file store for OAuth state: registered clients, pending authorization
 * codes, refresh tokens, and consent-login failure counts. Codes and refresh
 * tokens are stored as SHA-256 hashes only; expired entries are pruned on
 * every save. Every mutation runs load→mutate→save under an exclusive flock,
 * so two simultaneous requests can never both redeem the same single-use
 * code or refresh token.
 * ponytail: single JSON file + atomic rename + one global lock, same storage
 * idiom as the API plugin's ApiKeyManager; move to a real backend only if
 * lock contention ever bites (OAuth endpoints are rare by nature).
 */
class OAuthStore
{
    /**
     * Hard ceiling on unconsented client registrations. DCR is public by spec,
     * so without a bound a registration loop grows this file for a full day
     * before the age prune bites. Public so tests assert against the real value.
     */
    public const int MAX_PENDING = 200;

    private array $data;

    public function __construct(private readonly string $file)
    {
        $this->load();
    }

    public static function forGrav(Grav $grav): self
    {
        return new self($grav['locator']->findResource('user://data', true, true) . '/mcp-server/oauth.json');
    }

    public function getClient(string $clientId): ?array
    {
        $client = $this->data['clients'][$clientId] ?? null;

        return is_array($client) ? $client : null;
    }

    public function putClient(array $client): void
    {
        $this->mutate(function () use ($client): void {
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

            // Even inside the day window the file must stay bounded: beyond
            // MAX_PENDING unconsented clients, evict oldest-registered. A genuine
            // client's register→consent gap is minutes, so it only loses its slot
            // if a flood outruns the per-IP registration throttle from many IPs.
            $pending = array_filter(
                $this->data['clients'],
                static fn($c): bool => !isset($live[(string) ($c['client_id'] ?? '')]),
            );
            if (count($pending) >= self::MAX_PENDING) {
                uasort($pending, static fn($a, $b): int => ((int) ($a['created'] ?? 0)) <=> ((int) ($b['created'] ?? 0)));
                foreach (array_slice(array_keys($pending), 0, count($pending) - self::MAX_PENDING + 1) as $evict) {
                    unset($this->data['clients'][$evict]);
                }
            }

            $this->data['clients'][$client['client_id']] = $client;
        });
    }

    public function putCode(string $hash, array $code): void
    {
        $this->mutate(function () use ($hash, $code): void {
            $this->data['codes'][$hash] = $code;
        });
    }

    /** Fetch and delete: authorization codes are single-use. */
    public function takeCode(string $hash): ?array
    {
        $code = $this->mutate(function () use ($hash): ?array {
            $code = $this->data['codes'][$hash] ?? null;
            unset($this->data['codes'][$hash]);

            return is_array($code) ? $code : null;
        });

        return is_array($code) ? $code : null;
    }

    public function putRefresh(string $hash, array $token): void
    {
        $this->mutate(function () use ($hash, $token): void {
            $this->data['refresh_tokens'][$hash] = $token;
        });
    }

    /**
     * Fetch a refresh token. The first take marks it used (rotation) and
     * returns it live; a later take returns the tombstone with its 'used'
     * flag set, so the caller can treat the replay as theft (OAuth 2.1).
     * Tombstones keep the token's original expiry, so save() prunes them —
     * replay detection lasts exactly as long as the token would have.
     */
    public function takeRefresh(string $hash): ?array
    {
        $token = $this->mutate(function () use ($hash): ?array {
            $token = $this->data['refresh_tokens'][$hash] ?? null;
            if (is_array($token) && empty($token['used'])) {
                $this->data['refresh_tokens'][$hash]['used'] = true;
            }

            return is_array($token) ? $token : null;
        });

        return is_array($token) ? $token : null;
    }

    /**
     * Kill a refresh-token family (replay = theft): delete every member, live
     * or tombstoned, and return the key_ids of the live ones so the caller can
     * revoke their access keys. An empty family never sweeps — tokens minted
     * before families existed all share the missing value.
     *
     * @return list<string>
     */
    public function revokeFamily(string $family): array
    {
        if ($family === '') {
            return [];
        }

        $keyIds = $this->mutate(function () use ($family): array {
            $keyIds = [];
            foreach ($this->data['refresh_tokens'] as $hash => $token) {
                if (is_array($token) && (string) ($token['family'] ?? '') === $family) {
                    if (empty($token['used']) && !empty($token['key_id'])) {
                        $keyIds[(string) $token['key_id']] = true;
                    }
                    unset($this->data['refresh_tokens'][$hash]);
                }
            }

            return array_keys($keyIds);
        });

        return is_array($keyIds) ? $keyIds : [];
    }

    /** Sliding-window counter for a throttle key ('ip:...', 'user:...', 'reg:...'), 0 once expired. */
    public function failureCount(string $key): int
    {
        $entry = $this->data['failures'][$key] ?? null;
        return is_array($entry) && ((int) ($entry['expires'] ?? 0)) > time() ? (int) ($entry['count'] ?? 0) : 0;
    }

    /** Sliding window: every failure bumps the count and pushes expiry out again. */
    public function recordFailure(string $key, int $ttl): void
    {
        $this->mutate(function () use ($key, $ttl): void {
            $this->data['failures'][$key] = [
                'count' => $this->failureCount($key) + 1,
                'expires' => time() + $ttl,
            ];
        });
    }

    public function clearFailures(string ...$keys): void
    {
        $this->mutate(function () use ($keys): void {
            foreach ($keys as $key) {
                unset($this->data['failures'][$key]);
            }
        });
    }

    /** Drop refresh tokens tied to a revoked access key (RFC 7009 cascade). */
    public function deleteRefreshByKeyId(string $keyId): void
    {
        $this->mutate(function () use ($keyId): void {
            // Non-array entries (a corrupted store) are dropped too — self-heal
            // instead of TypeError-ing revocation into a 500.
            $this->data['refresh_tokens'] = array_filter(
                $this->data['refresh_tokens'],
                static fn($t): bool => is_array($t) && (string) ($t['key_id'] ?? '') !== $keyId,
            );
        });
    }

    private function load(): void
    {
        $this->data = is_file($this->file) ? ((array) json_decode((string) file_get_contents($this->file), true)) : [];
        $this->data += ['clients' => [], 'codes' => [], 'refresh_tokens' => [], 'failures' => []];
    }

    /**
     * One load→mutate→save under an exclusive lock. Reloading inside the lock
     * is the point: this instance's constructor snapshot may be stale by the
     * time the lock is won (another request redeemed the same code first).
     * The lock is a sibling file because save() replaces the data file by
     * rename — a lock taken on the data file itself would be orphaned on the
     * old inode.
     */
    private function mutate(callable $fn): mixed
    {
        $this->ensureDir();
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException(sprintf('Unable to open "%s.lock"', $this->file));
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException(sprintf('Unable to lock "%s.lock"', $this->file));
        }

        try {
            $this->load();
            $result = $fn();
            $this->save();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
        }
    }

    private function save(): void
    {
        $now = time();
        // Also drops non-array entries, so a corrupted store heals on save.
        $fresh = static fn($entry): bool => is_array($entry) && ((int) ($entry['expires'] ?? 0)) > $now;
        $this->data['codes'] = array_filter($this->data['codes'], $fresh);
        $this->data['refresh_tokens'] = array_filter($this->data['refresh_tokens'], $fresh);
        $this->data['failures'] = array_filter($this->data['failures'], $fresh);

        $this->ensureDir();

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
