<?php

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStore;

/**
 * FileStore
 *
 * @package MonkeysLegion\Cache\Stores
 */
class FileStore extends CacheStore
{
    /**
     * The directory where the cache files are stored.
     *
     * @var string
     */
    private string $directory;

    /**
     * Create a new file store instance.
     *
     * @param  string  $directory
     * @param  string  $prefix
     * @return void
     */
    public function __construct(string $directory, string $prefix = '')
    {
        parent::__construct($prefix);
        $this->directory = rtrim($directory, '/');
        
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
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
        $path = $this->path($key);

        if (!file_exists($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        $payload = $this->unserialize($contents);

        if ($this->isExpired($payload)) {
            $this->delete($key);
            return $default;
        }

        return $payload['value'];
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
        $expiration = $seconds === null ? null : time() + $seconds;

        $payload = $this->serialize([
            'value' => $value,
            'expiration' => $expiration,
            'time' => time()
        ]);

        $path = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return file_put_contents($path, $payload, LOCK_EX) !== false;
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $path = $this->path($key);

        if (file_exists($path)) {
            return unlink($path);
        }

        return true;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function clear(): bool
    {
        $this->clearDirectory($this->directory);
        return true;
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
            if (!$this->set($key, $value, $ttl)) {
                return false;
            }
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
     * Check if an item exists in the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
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
        
        if ($this->set($key, $new)) {
            return $new;
        }

        return false;
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
     * Get the full path for a cache key.
     *
     * @param  string  $key
     * @return string
     */
    private function path(string $key): string
    {
        $parts = array_slice(str_split($hash = md5($this->prepareKey($key)), 2), 0, 2);
        return $this->directory . '/' . implode('/', $parts) . '/' . $hash;
    }

    /**
     * Check if the payload is expired
     *
     * @param  array  $payload
     * @return bool
     */
    private function isExpired(array $payload): bool
    {
        return $payload['expiration'] !== null && time() >= $payload['expiration'];
    }

    /**
     * Recursively clear a directory
     *
     * @param  string  $directory
     * @return void
     */
    private function clearDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \FilesystemIterator($directory);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->clearDirectory($item->getPathname());
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }
}
