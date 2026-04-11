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
 */

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStats;
use MonkeysLegion\Cache\CacheStore;
use MonkeysLegion\Cache\CacheStoreInterface;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\PhpSerializer;

/**
 * L1/L2 tiered (chain) cache store.
 *
 * Reads cascade through layers (L1 → L2 → ... → miss).
 * On a miss at L1 but hit at L2, the value is promoted back to L1.
 * Writes go through all layers (write-through).
 *
 * Typical usage: ArrayStore (L1, fast in-process) → RedisStore (L2, shared).
 *
 * Uses PHP 8.4: final class, readonly property, property hooks.
 */
final class ChainStore extends CacheStore
{
    /** @var list<CacheStoreInterface> */
    private readonly array $stores;

    /**
     * Number of cache layers.
     */
    public int $layerCount {
        get => count($this->stores);
    }

    /**
     * @param list<CacheStoreInterface> $stores  Ordered layers (fastest first).
     */
    public function __construct(
        array $stores,
        string $prefix = '',
        CacheSerializerInterface $serializer = new PhpSerializer(),
    ) {
        if (count($stores) < 2) {
            throw new \InvalidArgumentException('ChainStore requires at least 2 cache layers.');
        }

        parent::__construct($prefix, $serializer);
        $this->stores = array_values($stores);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        foreach ($this->stores as $i => $store) {
            if (!$store->has($key)) {
                continue;
            }

            $value = $store->get($key, $default);
            $this->statHits++;

            // Promote to faster layers that missed
            for ($j = 0; $j < $i; $j++) {
                $this->stores[$j]->set($key, $value);
            }

            return $value;
        }

        $this->statMisses++;
        return $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $success = true;

        foreach ($this->stores as $store) {
            if (!$store->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        $this->statWrites++;
        return $success;
    }

    public function delete(string $key): bool
    {
        $success = true;

        foreach ($this->stores as $store) {
            if (!$store->delete($key)) {
                $success = false;
            }
        }

        $this->statDeletes++;
        return $success;
    }

    public function clear(): bool
    {
        $success = true;

        foreach ($this->stores as $store) {
            if (!$store->clear()) {
                $success = false;
            }
        }

        return $success;
    }

    public function has(string $key): bool
    {
        foreach ($this->stores as $store) {
            if ($store->has($key)) {
                return true;
            }
        }

        return false;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        // Increment on the last (authoritative) layer, then sync up
        $last   = $this->stores[count($this->stores) - 1];
        $result = $last->increment($key, $value);

        if ($result !== false) {
            // Sync the new value to all other layers
            for ($i = 0; $i < count($this->stores) - 1; $i++) {
                $this->stores[$i]->set($key, $result);
            }
        }

        return $result;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        $success = true;

        foreach ($this->stores as $store) {
            if (!$store->touch($key, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function getStats(): CacheStats
    {
        return new CacheStats(
            hits:   $this->statHits,
            misses: $this->statMisses,
            writes: $this->statWrites,
            deletes: $this->statDeletes,
            itemCount: $this->stores[0]->getStats()->itemCount,
        );
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyList = $keys instanceof \Traversable ? iterator_to_array($keys) : (array) $keys;
        $results = array_fill_keys($keyList, $default);
        $remaining = $keyList;

        foreach ($this->stores as $i => $store) {
            if ($remaining === []) {
                break;
            }

            $foundKeys = [];
            $foundValues = [];

            foreach ($remaining as $key) {
                if ($store->has($key)) {
                    $value = $store->get($key);
                    $results[$key] = $value;
                    $foundKeys[] = $key;
                    $foundValues[$key] = $value;
                    $this->statHits++;
                }
            }

            // Promote found values to faster layers that missed
            if ($i > 0 && $foundValues !== []) {
                for ($j = 0; $j < $i; $j++) {
                    $this->stores[$j]->setMultiple($foundValues);
                }
            }

            $remaining = array_values(array_diff($remaining, $foundKeys));
        }

        $this->statMisses += count($remaining);

        return $results;
    }

    /**
     * Get a specific cache layer by index.
     */
    public function layer(int $index): CacheStoreInterface
    {
        if (!isset($this->stores[$index])) {
            throw new \OutOfRangeException("Cache layer [{$index}] does not exist.");
        }

        return $this->stores[$index];
    }
}
