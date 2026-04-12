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
 * @requires  ext-memcached
 */

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStats;
use MonkeysLegion\Cache\CacheStore;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\PhpSerializer;

/**
 * Memcached cache store with native touch and CAS operations.
 *
 * Uses PHP 8.4: final class, new-in-initializer.
 */
final class MemcachedStore extends CacheStore
{
    public function __construct(
        private readonly \Memcached $memcached,
        string $prefix = '',
        CacheSerializerInterface $serializer = new PhpSerializer(),
    ) {
        parent::__construct($prefix, $serializer);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->memcached->get($this->prepareKey($key));

        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            $this->statMisses++;
            return $default;
        }

        $this->statHits++;
        return $value;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds    = $this->ttlToSeconds($ttl);
        $expiration = $seconds !== null ? time() + $seconds : 0;

        $this->statWrites++;
        return $this->memcached->set($this->prepareKey($key), $value, $expiration);
    }

    public function delete(string $key): bool
    {
        $this->statDeletes++;
        return $this->memcached->delete($this->prepareKey($key));
    }

    public function clear(): bool
    {
        return $this->memcached->flush();
    }

    public function has(string $key): bool
    {
        $this->memcached->get($this->prepareKey($key));
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
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

        $values  = $this->memcached->getMulti($preparedKeys) ?: [];
        $results = [];

        foreach ($preparedKeys as $prepared) {
            $original = $keyMap[$prepared];

            if (isset($values[$prepared])) {
                $this->statHits++;
                $results[$original] = $values[$prepared];
            } else {
                $this->statMisses++;
                $results[$original] = $default;
            }
        }

        return $results;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $seconds    = $this->ttlToSeconds($ttl);
        $expiration = $seconds !== null ? time() + $seconds : 0;
        $items      = [];

        foreach ($values as $key => $value) {
            $items[$this->prepareKey($key)] = $value;
            $this->statWrites++;
        }

        return $this->memcached->setMulti($items, $expiration);
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

        $this->memcached->deleteMulti($prepared);
        return true;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $result = $this->memcached->increment($this->prepareKey($key), $value);

        if ($result === false && $this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            $this->set($key, $value);
            return $value;
        }

        return $result;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        $result = $this->memcached->decrement($this->prepareKey($key), $value);

        if ($result === false && $this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            $this->set($key, 0);
            return 0;
        }

        return $result;
    }

    /**
     * Native Memcached TOUCH — extend TTL without re-reading value.
     */
    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        $seconds = $this->ttlToSeconds($ttl);

        if ($seconds === null) {
            return false;
        }

        return $this->memcached->touch($this->prepareKey($key), time() + $seconds);
    }

    /**
     * CAS-based atomic add (set if not exists).
     */
    public function add(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds    = $this->ttlToSeconds($ttl);
        $expiration = $seconds !== null ? time() + $seconds : 0;

        $result = $this->memcached->add($this->prepareKey($key), $value, $expiration);

        if ($result) {
            $this->statWrites++;
        }

        return $result;
    }

    public function getStats(): CacheStats
    {
        $stats = $this->memcached->getStats();
        $total = ['curr_items' => 0, 'bytes' => 0, 'get_hits' => 0, 'get_misses' => 0];

        foreach ($stats as $server) {
            $total['curr_items'] += (int) ($server['curr_items'] ?? 0);
            $total['bytes']      += (int) ($server['bytes'] ?? 0);
            $total['get_hits']   += (int) ($server['get_hits'] ?? 0);
            $total['get_misses'] += (int) ($server['get_misses'] ?? 0);
        }

        return new CacheStats(
            hits:        $this->statHits,
            misses:      $this->statMisses,
            writes:      $this->statWrites,
            deletes:     $this->statDeletes,
            itemCount:   $total['curr_items'],
            memoryUsage: $total['bytes'],
        );
    }

    public function getMemcached(): \Memcached
    {
        return $this->memcached;
    }
}
