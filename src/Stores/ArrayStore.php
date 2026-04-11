<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache\Stores
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStats;
use MonkeysLegion\Cache\CacheStore;
use MonkeysLegion\Cache\Lock\ArrayLock;
use MonkeysLegion\Cache\Lock\LockInterface;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\PhpSerializer;

/**
 * In-memory cache store for testing and single-request caching.
 *
 * Uses PHP 8.4: final class, new-in-initializer, match expressions.
 */
final class ArrayStore extends CacheStore
{
    /** @var array<string, mixed> */
    private array $storage = [];

    /** @var array<string, int> Key → expiration timestamp */
    private array $expirations = [];

    /** @var array<string, list<string>> Key → tag list */
    private array $tagMap = [];

    /** @var int Maximum number of items (0 = unlimited) */
    private readonly int $maxItems;

    public function __construct(
        string $prefix = '',
        CacheSerializerInterface $serializer = new PhpSerializer(),
        int $maxItems = 0,
    ) {
        parent::__construct($prefix, $serializer);
        $this->maxItems = $maxItems;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $prepared = $this->prepareKey($key);

        if (!array_key_exists($prepared, $this->storage)) {
            $this->statMisses++;
            return $default;
        }

        if ($this->isExpired($prepared)) {
            unset($this->storage[$prepared], $this->expirations[$prepared], $this->tagMap[$prepared]);
            $this->statMisses++;
            return $default;
        }

        // LRU: move accessed key to end of array (most recently used)
        $value = $this->storage[$prepared];
        unset($this->storage[$prepared]);
        $this->storage[$prepared] = $value;

        $this->statHits++;
        return $value;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $prepared = $this->prepareKey($key);
        $seconds  = $this->ttlToSeconds($ttl);

        // If already exists, remove first so it goes to end (most recent)
        unset($this->storage[$prepared]);

        $this->storage[$prepared] = $value;

        if ($seconds !== null) {
            $this->expirations[$prepared] = time() + $seconds;
        } else {
            unset($this->expirations[$prepared]);
        }

        // LRU eviction when maxItems is set
        if ($this->maxItems > 0 && count($this->storage) > $this->maxItems) {
            $this->evict();
        }

        $this->statWrites++;
        return true;
    }

    public function delete(string $key): bool
    {
        $prepared = $this->prepareKey($key);

        unset($this->storage[$prepared], $this->expirations[$prepared], $this->tagMap[$prepared]);

        $this->statDeletes++;
        return true;
    }

    public function clear(): bool
    {
        $this->storage     = [];
        $this->expirations = [];
        $this->tagMap      = [];

        return true;
    }

    public function has(string $key): bool
    {
        $prepared = $this->prepareKey($key);

        if (!array_key_exists($prepared, $this->storage)) {
            return false;
        }

        if ($this->isExpired($prepared)) {
            unset($this->storage[$prepared], $this->expirations[$prepared], $this->tagMap[$prepared]);
            return false;
        }

        return true;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = (int) $this->get($key, 0);
        $new     = $current + $value;

        $this->set($key, $new);

        return $new;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        $prepared = $this->prepareKey($key);

        if (!array_key_exists($prepared, $this->storage) || $this->isExpired($prepared)) {
            return false;
        }

        $seconds = $this->ttlToSeconds($ttl);
        $this->expirations[$prepared] = time() + ($seconds ?? 0);

        return true;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockInterface
    {
        return new ArrayLock($name, $seconds, $owner);
    }

    public function getStats(): CacheStats
    {
        // Remove expired items for accurate count
        foreach (array_keys($this->storage) as $key) {
            if ($this->isExpired($key)) {
                unset($this->storage[$key], $this->expirations[$key], $this->tagMap[$key]);
            }
        }

        return new CacheStats(
            hits:        $this->statHits,
            misses:      $this->statMisses,
            writes:      $this->statWrites,
            deletes:     $this->statDeletes,
            itemCount:   count($this->storage),
            memoryUsage: (int) (strlen(serialize($this->storage)) * 1.1),
        );
    }

    // ── Tag support (internal) ─────────────────────────────────

    /**
     * Store tags for a prepared key.
     *
     * @param string        $preparedKey Already-prefixed key.
     * @param list<string>  $tags        Tag names.
     */
    public function setTags(string $preparedKey, array $tags): void
    {
        $this->tagMap[$preparedKey] = $tags;
    }

    /**
     * Flush all keys matching any of the given tags.
     *
     * @param list<string> $tags Tags to invalidate.
     */
    public function flushTags(array $tags): bool
    {
        foreach ($this->tagMap as $key => $itemTags) {
            if (array_intersect($tags, $itemTags) !== []) {
                unset($this->storage[$key], $this->expirations[$key], $this->tagMap[$key]);
            }
        }

        return true;
    }

    /**
     * Get all non-expired items (for testing/debugging).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        foreach (array_keys($this->storage) as $key) {
            if ($this->isExpired($key)) {
                unset($this->storage[$key], $this->expirations[$key], $this->tagMap[$key]);
            }
        }

        return $this->storage;
    }

    // ── Private ────────────────────────────────────────────────

    private function isExpired(string $preparedKey): bool
    {
        if (!isset($this->expirations[$preparedKey])) {
            return false;
        }

        return time() >= $this->expirations[$preparedKey];
    }

    /**
     * Evict the least recently used items until under maxItems.
     */
    private function evict(): void
    {
        while (count($this->storage) > $this->maxItems) {
            $oldestKey = array_key_first($this->storage);

            if ($oldestKey === null) {
                break;
            }

            unset($this->storage[$oldestKey], $this->expirations[$oldestKey], $this->tagMap[$oldestKey]);
        }
    }
}
