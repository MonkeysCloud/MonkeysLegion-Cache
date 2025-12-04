<?php

namespace MonkeysLegion\Cache;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

/**
 * CacheInterface
 *
 * @package MonkeysLegion\Cache
 */
interface CacheInterface extends PsrCacheInterface
{
    /**
     * Store an item in the cache indefinitely
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Get an item from the cache, or execute the given Closure and store the result
     *
     * @param string $key
     * @param \DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public function remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed;

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever
     *
     * @param string $key
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberForever(string $key, \Closure $callback): mixed;

    /**
     * Increment the value of an item in the cache
     *
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function increment(string $key, int $value = 1): int|bool;

    /**
     * Decrement the value of an item in the cache
     *
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function decrement(string $key, int $value = 1): int|bool;

    /**
     * Store multiple items in the cache for a given number of seconds
     *
     * @param array $values
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    public function putMany(array $values, \DateInterval|int|null $ttl = null): bool;

    /**
     * Retrieve an item and delete it
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache if the key does not exist
     *
     * @param string $key
     * @param mixed $value
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool;

    /**
     * Get the cache key prefix
     *
     * @return string
     */
    public function getPrefix(): string;

    /**
     * Flush the cache with a specific tag
     *
     * @param array|string $names
     * @return self
     */
    public function tags(array|string $names): self;
}
