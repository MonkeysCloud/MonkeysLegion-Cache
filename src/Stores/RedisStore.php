<?php

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStore;

/**
 * RedisStore
 *
 * @package MonkeysLegion\Cache\Stores
 */
class RedisStore extends CacheStore
{
    /**
     * The Redis connection instance
     *
     * @var \Redis
     */
    private \Redis $redis;

    /**
     * Create a new store instance.
     *
     * @param \Redis $redis
     * @param string $prefix
     */
    public function __construct(\Redis $redis, string $prefix = '')
    {
        parent::__construct($prefix);
        $this->redis = $redis;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($this->prepareKey($key));

        if ($value === false) {
            return $default;
        }

        return $this->unserialize($value);
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param string $key
     * @param mixed $value
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->getSeconds($ttl);
        $key = $this->prepareKey($key);
        $value = $this->serialize($value);

        if ($seconds === null) {
            return $this->redis->set($key, $value);
        }

        return $this->redis->setex($key, $seconds, $value);
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        return $this->redis->del($this->prepareKey($key)) > 0;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function clear(): bool
    {
        if (!empty($this->tags)) {
            return $this->flushTags();
        }

        return $this->redis->flushDB();
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @param iterable $keys
     * @param mixed $default
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

        $values = $this->redis->mGet($preparedKeys);
        $results = [];

        foreach ($values as $index => $value) {
            $originalKey = $keyMap[$preparedKeys[$index]];
            $results[$originalKey] = $value !== false ? $this->unserialize($value) : $default;
        }

        return $results;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     *
     * @param iterable $values
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->getSeconds($ttl);
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $preparedKey = $this->prepareKey($key);
            $serialized = $this->serialize($value);

            if ($seconds === null) {
                $pipeline->set($preparedKey, $serialized);
            } else {
                $pipeline->setex($preparedKey, $seconds, $serialized);
            }
        }

        $results = $pipeline->exec();
        
        return !in_array(false, $results, true);
    }

    /**
     * Remove multiple items from the cache.
     *
     * @param iterable $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $preparedKeys = [];
        
        foreach ($keys as $key) {
            $preparedKeys[] = $this->prepareKey($key);
        }

        $this->redis->del($preparedKeys);
        return true;
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->redis->exists($this->prepareKey($key)) > 0;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->redis->incrBy($this->prepareKey($key), $value);
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param int $value
     * @return int|bool
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->redis->decrBy($this->prepareKey($key), $value);
    }

    /**
     * Store an item in the cache indefinitely
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->redis->set($this->prepareKey($key), $this->serialize($value));
    }

    /**
     * Get the Redis connection instance
     *
     * @return \Redis
     */
    public function getRedis(): \Redis
    {
        return $this->redis;
    }

    /**
     * Flush cache by tags
     *
     * @return bool
     */
    private function flushTags(): bool
    {
        $pattern = implode(':', $this->tags) . ':*';
        $keys = $this->redis->keys($pattern);

        if (empty($keys)) {
            return true;
        }

        return $this->redis->del($keys) > 0;
    }
}
