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

/**
 * No-op cache store — reads always miss, writes always succeed.
 *
 * Use for testing environments or disabling cache entirely.
 */
final class NullStore extends CacheStore
{
    public function get(string $key, mixed $default = null): mixed
    {
        $this->statMisses++;
        return $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->statWrites++;
        return true;
    }

    public function delete(string $key): bool
    {
        $this->statDeletes++;
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        return $value;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return -$value;
    }

    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        return false;
    }

    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->statWrites++;
        return true;
    }

    public function getStats(): CacheStats
    {
        return new CacheStats(
            hits:   $this->statHits,
            misses: $this->statMisses,
            writes: $this->statWrites,
            deletes: $this->statDeletes,
        );
    }
}
