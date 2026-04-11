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

/**
 * Tag-aware cache decorator with version-based O(1) invalidation.
 *
 * Instead of scanning all keys for matching tags (expensive on Redis),
 * we use tag versioning: each tag has a version number. When tags are
 * invalidated, the version increments, causing all keys with the old
 * version to effectively "miss" on the next read.
 *
 * Uses PHP 8.4: final class, readonly properties, property hooks.
 */
final class TaggedCache
{
    /**
     * The computed tag namespace (version-aware key prefix).
     * Uses pipe separator to avoid ambiguity with tag names containing dots.
     */
    public string $tagNamespace {
        get => 'tag|' . implode('|', $this->tags) . '|v' . $this->getTagVersion();
    }

    /**
     * @param CacheStoreInterface $store  The underlying store.
     * @param list<string>        $tags   Tag names for this scope.
     */
    public function __construct(
        private readonly CacheStoreInterface $store,
        private readonly array $tags,
    ) {}

    // ── Proxied cache operations (scoped by tag namespace) ─────

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->taggedKey($key), $default);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->store->set($this->taggedKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($this->taggedKey($key));
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->taggedKey($key));
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store->forever($this->taggedKey($key), $value);
    }

    public function remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed
    {
        return $this->store->remember($this->taggedKey($key), $ttl, $callback);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->store->pull($this->taggedKey($key), $default);
    }

    public function increment(string $key, int $value = 1): int|false
    {
        return $this->store->increment($this->taggedKey($key), $value);
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->store->decrement($this->taggedKey($key), $value);
    }

    // ── Batch operations ───────────────────────────────────────

    /**
     * Get multiple items scoped to tags.
     *
     * @param iterable<string> $keys
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $taggedMap = [];
        $taggedKeys = [];

        foreach ($keys as $key) {
            $tagged = $this->taggedKey($key);
            $taggedMap[$tagged] = $key;
            $taggedKeys[] = $tagged;
        }

        $taggedResults = $this->store->getMultiple($taggedKeys, $default);
        $results = [];

        foreach ($taggedResults as $taggedKey => $value) {
            $originalKey = $taggedMap[$taggedKey] ?? $taggedKey;
            $results[$originalKey] = $value;
        }

        return $results;
    }

    /**
     * Set multiple items scoped to tags.
     *
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $taggedValues = [];

        foreach ($values as $key => $value) {
            $taggedValues[$this->taggedKey($key)] = $value;
        }

        return $this->store->setMultiple($taggedValues, $ttl);
    }

    /**
     * Delete multiple items scoped to tags.
     *
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $taggedKeys = [];

        foreach ($keys as $key) {
            $taggedKeys[] = $this->taggedKey($key);
        }

        return $this->store->deleteMultiple($taggedKeys);
    }

    // ── Tag invalidation ───────────────────────────────────────

    /**
     * Invalidate all cache entries associated with the current tags.
     *
     * This is O(1) — we simply increment the tag version, which causes
     * all existing tagged keys to become unreachable.
     */
    public function flush(): bool
    {
        foreach ($this->tags as $tag) {
            $versionKey = $this->tagVersionKey($tag);
            // Use set() with a new unique version to avoid increment/serialization conflicts
            $this->store->set($versionKey, (string) hrtime(true));
        }

        return true;
    }

    /**
     * Alias for flush() for PSR-16 parity.
     */
    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * Static method to invalidate specific tags on any store.
     *
     * @param CacheStoreInterface $store The store to invalidate on.
     * @param list<string>        $tags  Tags to invalidate.
     */
    public static function invalidateTags(CacheStoreInterface $store, array $tags): bool
    {
        foreach ($tags as $tag) {
            $store->set('mltagv.' . $tag, (string) hrtime(true));
        }

        return true;
    }

    // ── Private ────────────────────────────────────────────────

    /**
     * Build the scoped/tagged cache key.
     */
    private function taggedKey(string $key): string
    {
        return $this->tagNamespace . '.' . $key;
    }

    /**
     * Get the combined version of all current tags.
     *
     * Each tag has its own version counter; we hash them together
     * to produce a single namespace prefix.
     */
    private function getTagVersion(): string
    {
        $versions = [];

        foreach ($this->tags as $tag) {
            $version    = $this->store->get($this->tagVersionKey($tag));
            $versions[] = $version ?? '0';
        }

        return implode('.', $versions);
    }

    /**
     * The key that stores a tag's version counter.
     */
    private function tagVersionKey(string $tag): string
    {
        return 'mltagv.' . $tag;
    }
}
