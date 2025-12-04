<?php

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStore;

/**
 * MemcachedStore
 *
 * @package MonkeysLegion\Cache\Stores
 */
class MemcachedStore extends CacheStore
{
    /**
     * The Memcached connection instance
     *
     * @var \Memcached
     */
    private \Memcached $memcached;

    /**
     * Create a new memcached store instance.
     *
     * @param  \Memcached  $memcached
     * @param  string  $prefix
     * @return void
     */
    public function __construct(\Memcached $memcached, string $prefix = '')
    {
        parent::__construct($prefix);
        $this->memcached = $memcached;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->prepareKey($key));

        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            return $default;
        }

        return $value;
    }

    /**
     * Store an item in the cache.
     *
     * @param  string  $key
     * @param  mixed   $value
     * @param  \DateInterval|int|null  $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->getSeconds($ttl);
        $expiration = $seconds === null ? 0 : time() + $seconds;

        return $this->memcached->set($this->prepareKey($key), $value, $expiration);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return $this->memcached->delete($this->prepareKey($key));
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @param  iterable  $keys
     * @param  mixed   $default
     * @return iterable
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $preparedKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $preparedKey = $this->prepareKey($key);
            $preparedKeys[] = $preparedKey;
            $keyMap[$preparedKey] = $key;
        }

        $values = $this->memcached->getMulti($preparedKeys);
        $results = [];

        foreach ($preparedKeys as $preparedKey) {
            $originalKey = $keyMap[$preparedKey];
            $results[$originalKey] = $values[$preparedKey] ?? $default;
        }

        return $results;
    }

    /**
     * Store multiple items in the cache.
     *
     * @param  iterable  $values
     * @param  \DateInterval|int|null  $ttl
     * @return bool
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->getSeconds($ttl);
        $expiration = $seconds === null ? 0 : time() + $seconds;
        
        $items = [];
        
        foreach ($values as $key => $value) {
            $items[$this->prepareKey($key)] = $value;
        }

        return $this->memcached->setMulti($items, $expiration);
    }

    /**
     * Remove multiple items from the cache.
     *
     * @param  iterable  $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $preparedKeys = [];
        
        foreach ($keys as $key) {
            $preparedKeys[] = $this->prepareKey($key);
        }

        $this->memcached->deleteMulti($preparedKeys);
        
        return true;
    }

    /**
     * Check if an item exists in the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->memcached->get($this->prepareKey($key));
        
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  int  $value
     * @return int|bool
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        $result = $this->memcached->increment($this->prepareKey($key), $value);

        if ($result === false && $this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            $this->set($key, $value);
            return $value;
        }

        return $result;
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  int  $value
     * @return int|bool
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        $result = $this->memcached->decrement($this->prepareKey($key), $value);

        if ($result === false && $this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            $this->set($key, 0);
            return 0;
        }

        return $result;
    }

    /**
     * Get the Memcached connection instance
     *
     * @return \Memcached
     */
    public function getMemcached(): \Memcached
    {
        return $this->memcached;
    }
}
