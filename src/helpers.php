<?php

use MonkeysLegion\Cache\Cache;
use MonkeysLegion\Cache\CacheInterface;

if (!function_exists('cache')) {
    /**
     * Get / set the specified cache value
     *
     * If an array is passed, we'll assume you want to put to the cache.
     * If a key and value are passed, we'll set that value.
     * If only a key is passed, we'll retrieve that value.
     * If no arguments, we'll return the cache manager instance.
     *
     * @param null|string|array $key
     * @param mixed $value
     * @return mixed
     */
    function cache(null|string|array $key = null, mixed $value = null): mixed
    {
        if (is_null($key)) {
            return Cache::getInstance();
        }

        if (is_array($key)) {
            return Cache::putMany($key);
        }

        if ($value !== null) {
            return Cache::set($key, $value);
        }

        return Cache::get($key);
    }
}

if (!function_exists('cache_remember')) {
    /**
     * Get an item from the cache, or execute the given Closure and store the result
     *
     * @param string $key
     * @param \DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    function cache_remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed
    {
        return Cache::remember($key, $ttl, $callback);
    }
}

if (!function_exists('cache_forever')) {
    /**
     * Get an item from the cache, or execute the given Closure and store the result forever
     *
     * @param string $key
     * @param \Closure $callback
     * @return mixed
     */
    function cache_forever(string $key, \Closure $callback): mixed
    {
        return Cache::rememberForever($key, $callback);
    }
}

if (!function_exists('cache_forget')) {
    /**
     * Remove an item from the cache
     *
     * @param string $key
     * @return bool
     */
    function cache_forget(string $key): bool
    {
        return Cache::delete($key);
    }
}

if (!function_exists('cache_flush')) {
    /**
     * Remove all items from the cache
     *
     * @return bool
     */
    function cache_flush(): bool
    {
        return Cache::clear();
    }
}

if (!function_exists('cache_has')) {
    /**
     * Determine if an item exists in the cache
     *
     * @param string $key
     * @return bool
     */
    function cache_has(string $key): bool
    {
        return Cache::has($key);
    }
}

if (!function_exists('cache_pull')) {
    /**
     * Retrieve an item from the cache and delete it
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function cache_pull(string $key, mixed $default = null): mixed
    {
        return Cache::pull($key, $default);
    }
}

if (!function_exists('cache_add')) {
    /**
     * Store an item in the cache if the key does not exist
     *
     * @param string $key
     * @param mixed $value
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    function cache_add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return Cache::add($key, $value, $ttl);
    }
}
