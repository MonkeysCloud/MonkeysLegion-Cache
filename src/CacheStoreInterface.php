<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 * @link      https://github.com/MonkeysCloud/MonkeysLegion-Cache
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache;

use Psr\SimpleCache\CacheInterface as PsrSimpleCacheInterface;
use MonkeysLegion\Cache\Lock\LockInterface;

/**
 * Extended cache store contract.
 *
 * Extends PSR-16 SimpleCache with atomic operations, typed getters,
 * stampede protection, tags, distributed locks, and observability.
 */
interface CacheStoreInterface extends PsrSimpleCacheInterface
{
    // ── Laravel 13 parity ──────────────────────────────────────

    /**
     * Get an item or execute the callback and store the result.
     */
    public function remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed;

    /**
     * Get an item or execute the callback and store the result forever.
     */
    public function rememberForever(string $key, \Closure $callback): mixed;

    /**
     * Store an item indefinitely.
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Retrieve an item and delete it.
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Store if key does not exist (atomic when driver supports it).
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool;

    /**
     * Extend the TTL of an item without re-reading its value.
     */
    public function touch(string $key, \DateInterval|int $ttl): bool;

    /**
     * Atomically increment an integer value.
     */
    public function increment(string $key, int $value = 1): int|false;

    /**
     * Atomically decrement an integer value.
     */
    public function decrement(string $key, int $value = 1): int|false;

    // ── Typed getters ──────────────────────────────────────────

    public function integer(string $key, int $default = 0): int;

    public function boolean(string $key, bool $default = false): bool;

    public function float(string $key, float $default = 0.0): float;

    public function string(string $key, string $default = ''): string;

    /** @return array<mixed> */
    public function array(string $key, array $default = []): array;

    // ── Stampede protection ────────────────────────────────────

    /**
     * Stale-while-revalidate pattern.
     *
     * @param string   $key      Cache key.
     * @param array{0: int, 1: int} $ttl  [stale_ttl, fresh_ttl] in seconds.
     * @param \Closure $callback Callback to compute on miss or staleness.
     * @param float    $beta     Stampede protection factor (1.0 = default).
     */
    public function flexible(string $key, array $ttl, \Closure $callback, float $beta = 1.0): mixed;

    // ── Tags & Locks ───────────────────────────────────────────

    /**
     * Return a tag-aware cache wrapper.
     */
    public function tags(string|array $names): TaggedCache;

    /**
     * Acquire a distributed lock.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockInterface;

    // ── Observability ──────────────────────────────────────────

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string;

    /**
     * Return cache store statistics.
     */
    public function getStats(): CacheStats;
}
