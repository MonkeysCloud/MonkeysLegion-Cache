<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache;

use MonkeysLegion\Cache\Lock\LockInterface;
use MonkeysLegion\Cache\Lock\ArrayLock;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\PhpSerializer;

/**
 * Abstract cache store with shared logic for all drivers.
 *
 * Uses PHP 8.4 property hooks, `new` in initializers, and match expressions.
 * Provides default implementations for remember, flexible, typed getters, etc.
 */
abstract class CacheStore implements CacheStoreInterface
{
    /** @var int Internal hit counter */
    protected int $statHits = 0;

    /** @var int Internal miss counter */
    protected int $statMisses = 0;

    /** @var int Internal write counter */
    protected int $statWrites = 0;

    /** @var int Internal delete counter */
    protected int $statDeletes = 0;

    /**
     * Prefix applied to all cache keys.
     */
    protected string $prefix;

    public function __construct(
        string $prefix = '',
        protected readonly CacheSerializerInterface $serializer = new PhpSerializer(),
    ) {
        $this->prefix = $prefix !== '' ? rtrim($prefix, ':') . ':' : '';
    }

    // ── Template methods: remember / rememberForever ────────────

    public function remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->forever($key, $value);

        return $value;
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);

        return $value;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    // ── Stampede protection: flexible() ─────────────────────────

    public function flexible(string $key, array $ttl, \Closure $callback, float $beta = 1.0): mixed
    {
        [$staleTtl, $freshTtl] = $ttl;

        // Store internal CacheEntry with metadata
        $entryKey = '__flex:' . $key;
        $raw      = $this->getRaw($entryKey);

        if ($raw !== null) {
            $entry = $this->serializer->unserialize($raw);

            if ($entry instanceof CacheEntry) {
                // Still fresh — return directly
                if (!$entry->isExpired && !$entry->shouldRefresh($beta)) {
                    $this->statHits++;
                    return $entry->value;
                }

                // Stale but within stale window — return stale, let next request recompute
                if ($entry->expiresAt !== null && time() < $entry->expiresAt + $staleTtl) {
                    $this->statHits++;
                    return $entry->value;
                }
            }
        }

        // Compute fresh value
        $this->statMisses++;
        $value = $callback();

        $entry = new CacheEntry(
            value:     $value,
            expiresAt: time() + $freshTtl,
        );

        $this->setRaw($entryKey, $this->serializer->serialize($entry), $staleTtl + $freshTtl);

        return $value;
    }

    // ── Typed getters ──────────────────────────────────────────

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value !== null ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value !== null ? (bool) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        return $value !== null ? (float) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return $value !== null ? (string) $value : $default;
    }

    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : $default;
    }

    // ── Tags (default: returns TaggedCache wrapper) ─────────────

    public function tags(string|array $names): TaggedCache
    {
        $names = is_array($names) ? $names : [$names];

        return new TaggedCache($this, $names);
    }

    // ── Locks (default: in-memory array lock) ───────────────────

    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockInterface
    {
        return new ArrayLock($name, $seconds, $owner);
    }

    // ── Observability ──────────────────────────────────────────

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getStats(): CacheStats
    {
        return new CacheStats(
            hits:      $this->statHits,
            misses:    $this->statMisses,
            writes:    $this->statWrites,
            deletes:   $this->statDeletes,
        );
    }

    // ── PSR-16 batch defaults ──────────────────────────────────

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    // ── Key helpers ────────────────────────────────────────────

    /**
     * Build a prefixed cache key.
     *
     * @throws \InvalidArgumentException If the key contains reserved characters.
     */
    protected function prepareKey(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key must not be empty.');
        }

        // PSR-16 reserved characters
        if (preg_match('/[{}()\/@:\\\\]/', $key)) {
            throw new \InvalidArgumentException(
                "Cache key [{$key}] contains reserved characters: {}()/\\@:",
            );
        }

        return $this->prefix . $key;
    }

    /**
     * Convert TTL to seconds.
     */
    protected function ttlToSeconds(\DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return (int) (new \DateTime())->add($ttl)->format('U') - time();
        }

        return max(0, $ttl);
    }

    // ── Raw get/set for internal use (bypass serialization) ────

    /**
     * Get raw (already-serialized) string from the store.
     * Override in drivers for performance.
     */
    protected function getRaw(string $key): ?string
    {
        $value = $this->get($key);

        return $value !== null ? $this->serializer->serialize($value) : null;
    }

    /**
     * Set raw (already-serialized) string into the store.
     * Override in drivers for performance.
     */
    protected function setRaw(string $key, string $data, int $ttl): bool
    {
        return $this->set($key, $this->serializer->unserialize($data), $ttl);
    }
}
