<?php

namespace MonkeysLegion\Cache;

/**
 * CacheStore
 *
 * @package MonkeysLegion\Cache
 */
abstract class CacheStore implements CacheInterface
{
    /**
     * The cache key prefix
     *
     * @var string
     */
    protected string $prefix = '';

    /**
     * The tags for the cache store
     *
     * @var array
     */
    protected array $tags = [];

    /**
     * Create a new CacheStore instance
     *
     * @param string $prefix
     */
    public function __construct(string $prefix = '')
    {
        $this->prefix = $prefix;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result
     *
     * @param string $key
     * @param \DateInterval|int|null $ttl
     * @param \Closure $callback
     * @return mixed
     */
    public function remember(string $key, \DateInterval|int|null $ttl, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever
     *
     * @param string $key
     * @param \Closure $callback
     * @return mixed
     */
    public function rememberForever(string $key, \Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->forever($key, $value);

        return $value;
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
        return $this->set($key, $value, null);
    }

    /**
     * Store multiple items in the cache for a given number of seconds
     *
     * @param array $values
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    public function putMany(array $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->setMultiple($values, $ttl);
    }

    /**
     * Retrieve an item and delete it
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        
        return $value;
    }

    /**
     * Store an item in the cache if the key does not exist
     *
     * @param string $key
     * @param mixed $value
     * @param \DateInterval|int|null $ttl
     * @return bool
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    /**
     * Get the cache key prefix
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Prepare a cache key with prefix
     *
     * @param string $key
     * @return string
     */
    protected function prepareKey(string $key): string
    {
        if (empty($key)) {
            throw new \InvalidArgumentException('Cache key cannot be empty.');
        }

        $prepared = $this->prefix . $key;

        if (!empty($this->tags)) {
            $prepared = implode(':', $this->tags) . ':' . $prepared;
        }

        return $prepared;
    }

    /**
     * Convert TTL to seconds
     *
     * @param \DateInterval|int|null $ttl
     * @return ?int
     */
    protected function getSeconds(\DateInterval|int|null $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return (int) (new \DateTime())->add($ttl)->format('U') - time();
        }

        return (int) $ttl;
    }

    /**
     * Serialize value for storage
     *
     * @param mixed $value
     * @return string
     */
    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Unserialize value from storage
     *
     * @param string $value
     * @return mixed
     */
    protected function unserialize(string $value): mixed
    {
        return unserialize($value);
    }

    /**
     * Set tags for cache operations
     *
     * @param array|string $names
     * @return static
     */
    public function tags(array|string $names): static
    {
        $clone = clone $this;
        $clone->tags = is_array($names) ? $names : [$names];
        return $clone;
    }
}
