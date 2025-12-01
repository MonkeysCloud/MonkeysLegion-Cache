<?php

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStore;

/**
 * ArrayStore
 *
 * @package MonkeysLegion\Cache\Stores
 */
class ArrayStore extends CacheStore
{
    /**
     * The array of items stored in the cache.
     *
     * @var object
     */
    private object $data;

    /**
     * Create a new array store instance.
     *
     * @param  string  $prefix
     * @return void
     */
    public function __construct(string $prefix = '')
    {
        parent::__construct($prefix);
        
        $this->data = new class {
            public array $storage = [];
            public array $expirations = [];
            public array $tagMap = [];
        };
    }

    /**
     * Get an item from the cache.
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->prepareKey($key);

        if (!array_key_exists($key, $this->data->storage)) {
            return $default;
        }

        if ($this->isExpired($key)) {
            $this->delete($key);
            return $default;
        }

        return $this->data->storage[$key];
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
        $key = $this->prepareKey($key);
        $this->data->storage[$key] = $value;

        if (!empty($this->tags)) {
            $this->data->tagMap[$key] = $this->tags;
        }

        $seconds = $this->getSeconds($ttl);
        
        if ($seconds !== null) {
            $this->data->expirations[$key] = time() + $seconds;
        } else {
            unset($this->data->expirations[$key]);
        }

        return true;
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $key = $this->prepareKey($key);
        
        unset(
            $this->data->storage[$key], 
            $this->data->expirations[$key],
            $this->data->tagMap[$key]
        );
        
        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function clear(): bool
    {
        if (!empty($this->tags)) {
            return $this->clearTags();
        }

        $this->data->storage = [];
        $this->data->expirations = [];
        $this->data->tagMap = [];
        
        return true;
    }

    /**
     * Get multiple items from the cache.
     *
     * @param  iterable  $keys
     * @param  mixed   $default
     * @return iterable
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
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
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * Remove multiple items from the cache.
     *
     * @param  iterable  $keys
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $key = $this->prepareKey($key);
        
        if (!array_key_exists($key, $this->data->storage)) {
            return false;
        }

        if ($this->isExpired($key)) {
            $this->delete($key);
            return false;
        }

        return true;
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
        $current = (int) $this->get($key, 0);
        $new = $current + $value;
        
        $this->set($key, $new);
        
        return $new;
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
        return $this->increment($key, -$value);
    }

    /**
     * Get all items in storage (for testing/debugging)
     *
     * @return array
     */
    public function all(): array
    {
        // Remove expired items
        foreach (array_keys($this->data->storage) as $key) {
            if ($this->isExpired($key)) {
                unset(
                    $this->data->storage[$key], 
                    $this->data->expirations[$key],
                    $this->data->tagMap[$key]
                );
            }
        }

        return $this->data->storage;
    }

    /**
     * Check if a key is expired
     *
     * @param  string  $key
     * @return bool
     */
    private function isExpired(string $key): bool
    {
        if (!isset($this->data->expirations[$key])) {
            return false;
        }

        return time() >= $this->data->expirations[$key];
    }

    /**
     * Clear items matching current tags
     *
     * @return bool
     */
    private function clearTags(): bool
    {
        $tagsToClear = $this->tags;
        
        foreach ($this->data->tagMap as $key => $tags) {
            // Check if any of the tags to clear are present in the item's tags
            if (count(array_intersect($tagsToClear, $tags)) > 0) {
                unset(
                    $this->data->storage[$key], 
                    $this->data->expirations[$key],
                    $this->data->tagMap[$key]
                );
            }
        }

        return true;
    }
}
