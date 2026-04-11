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
 * @requires  ext-redis
 */

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStats;
use MonkeysLegion\Cache\CacheStore;
use MonkeysLegion\Cache\Lock\LockInterface;
use MonkeysLegion\Cache\Lock\RedisLock;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\PhpSerializer;

/**
 * Redis cache store with native atomic operations and pipeline batching.
 *
 * Uses PHP 8.4: final class, new-in-initializer.
 */
final class RedisStore extends CacheStore
{
    public function __construct(
        private readonly \Redis $redis,
        string $prefix = '',
        CacheSerializerInterface $serializer = new PhpSerializer(),
    ) {
        parent::__construct($prefix, $serializer);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($this->prepareKey($key));

        if ($value === false) {
            $this->statMisses++;
            return $default;
        }

        $this->statHits++;
        return $this->serializer->unserialize($value);
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds = $this->ttlToSeconds($ttl);
        $prepared = $this->prepareKey($key);
        $serialized = $this->serializer->serialize($value);

        $this->statWrites++;

        if ($seconds === null || $seconds === 0) {
            return $this->redis->set($prepared, $serialized);
        }

        return $this->redis->setex($prepared, $seconds, $serialized);
    }

    public function delete(string $key): bool
    {
        $this->statDeletes++;
        return $this->redis->del($this->prepareKey($key)) > 0;
    }

    public function clear(): bool
    {
        if ($this->prefix !== '') {
            // Only clear keys with our prefix (safe for shared Redis)
            $pattern = $this->prefix . '*';
            $cursor  = null;

            do {
                $scan = $this->redis->scan($cursor, $pattern, 1000);

                if ($scan !== false && $scan !== []) {
                    // UNLINK is non-blocking (async key removal in Redis ≥4.0)
                    $this->redis->unlink($scan);
                }
            } while ($cursor > 0);

            return true;
        }

        return $this->redis->flushDB(true); // async flush
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($this->prepareKey($key)) > 0;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $preparedKeys = [];
        $keyMap       = [];

        foreach ($keys as $key) {
            $prepared       = $this->prepareKey($key);
            $preparedKeys[] = $prepared;
            $keyMap[$prepared] = $key;
        }

        $values  = $this->redis->mGet($preparedKeys);
        $results = [];

        foreach ($values as $i => $value) {
            $originalKey = $keyMap[$preparedKeys[$i]];

            if ($value !== false) {
                $this->statHits++;
                $results[$originalKey] = $this->serializer->unserialize($value);
            } else {
                $this->statMisses++;
                $results[$originalKey] = $default;
            }
        }

        return $results;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $seconds  = $this->ttlToSeconds($ttl);
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $prepared   = $this->prepareKey($key);
            $serialized = $this->serializer->serialize($value);

            if ($seconds === null || $seconds === 0) {
                $pipeline->set($prepared, $serialized);
            } else {
                $pipeline->setex($prepared, $seconds, $serialized);
            }

            $this->statWrites++;
        }

        $results = $pipeline->exec();

        return !in_array(false, $results, true);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $prepared = [];

        foreach ($keys as $key) {
            $prepared[] = $this->prepareKey($key);
            $this->statDeletes++;
        }

        if ($prepared === []) {
            return true;
        }

        $this->redis->del($prepared);
        return true;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        return $this->redis->incrBy($this->prepareKey($key), $value);
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->redis->decrBy($this->prepareKey($key), $value);
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->statWrites++;
        return $this->redis->set(
            $this->prepareKey($key),
            $this->serializer->serialize($value),
        );
    }

    /**
     * Native EXPIRE — single round-trip (Laravel 13 Cache::touch parity).
     */
    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        $seconds = $this->ttlToSeconds($ttl);

        if ($seconds === null || $seconds <= 0) {
            return false;
        }

        return $this->redis->expire($this->prepareKey($key), $seconds);
    }

    /**
     * Atomic SET NX — set if not exists.
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds  = $this->ttlToSeconds($ttl);
        $prepared = $this->prepareKey($key);
        $serialized = $this->serializer->serialize($value);

        if ($seconds !== null && $seconds > 0) {
            // SET key value EX seconds NX
            $result = $this->redis->set($prepared, $serialized, ['NX', 'EX' => $seconds]);
        } else {
            $result = $this->redis->setnx($prepared, $serialized);
        }

        if ($result) {
            $this->statWrites++;
        }

        return (bool) $result;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockInterface
    {
        return new RedisLock($this->redis, $name, $seconds, $owner);
    }

    public function getStats(): CacheStats
    {
        $info = $this->redis->info('memory');

        return new CacheStats(
            hits:        $this->statHits,
            misses:      $this->statMisses,
            writes:      $this->statWrites,
            deletes:     $this->statDeletes,
            itemCount:   (int) $this->redis->dbSize(),
            memoryUsage: (int) ($info['used_memory'] ?? 0),
        );
    }

    /**
     * Get the underlying Redis connection.
     */
    public function getRedis(): \Redis
    {
        return $this->redis;
    }

    // ── Raw get/set for internal flexible() support ────────────

    protected function getRaw(string $key): ?string
    {
        $value = $this->redis->get($this->prepareKey($key));
        return $value !== false ? $value : null;
    }

    protected function setRaw(string $key, string $data, int $ttl): bool
    {
        return $this->redis->setex($this->prepareKey($key), $ttl, $data);
    }
}
