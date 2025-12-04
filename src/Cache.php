<?php

namespace MonkeysLegion\Cache;

/**
 * Cache Facade
 * 
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool set(string $key, mixed $value, \DateInterval|int|null $ttl = null)
 * @method static bool delete(string $key)
 * @method static bool clear()
 * @method static iterable getMultiple(iterable $keys, mixed $default = null)
 * @method static bool setMultiple(iterable $values, \DateInterval|int|null $ttl = null)
 * @method static bool deleteMultiple(iterable $keys)
 * @method static bool has(string $key)
 * @method static bool forever(string $key, mixed $value)
 * @method static mixed remember(string $key, \DateInterval|int|null $ttl, \Closure $callback)
 * @method static mixed rememberForever(string $key, \Closure $callback)
 * @method static int|bool increment(string $key, int $value = 1)
 * @method static int|bool decrement(string $key, int $value = 1)
 * @method static bool putMany(array $values, \DateInterval|int|null $ttl = null)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static bool add(string $key, mixed $value, \DateInterval|int|null $ttl = null)
 * @method static CacheInterface tags(array|string $names)
 * @method static CacheInterface store(?string $name = null)
 */
class Cache
{
    /**
     * The cache manager instance
     *
     * @var CacheManager|null
     */
    private static ?CacheManager $instance = null;

    /**
     * Set the cache manager instance
     *
     * @param CacheManager $manager
     */
    public static function setInstance(CacheManager $manager): void
    {
        self::$instance = $manager;
    }

    /**
     * Get the cache manager instance
     *
     * @return CacheManager
     */
    public static function getInstance(): CacheManager
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Cache manager instance has not been set.');
        }

        return self::$instance;
    }

    /**
     * Handle dynamic static calls
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        return self::getInstance()->$method(...$parameters);
    }
}
