<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache;

/**
 * Immutable cache statistics value object.
 *
 * Uses PHP 8.4 property hooks for computed `hitRate` and `memoryFormatted`.
 * Asymmetric visibility instead of readonly class (hooked properties
 * are incompatible with readonly).
 */
final class CacheStats
{
    public function __construct(
        public private(set) int $hits        = 0,
        public private(set) int $misses      = 0,
        public private(set) int $writes      = 0,
        public private(set) int $deletes     = 0,
        public private(set) int $itemCount   = 0,
        public private(set) int $memoryUsage = 0,
    ) {}

    /**
     * Cache hit rate as a float between 0.0 and 1.0.
     */
    public float $hitRate {
        get {
            $total = $this->hits + $this->misses;
            return $total > 0 ? round($this->hits / $total, 4) : 0.0;
        }
    }

    /**
     * Human-readable memory usage.
     */
    public string $memoryFormatted {
        get => match (true) {
            $this->memoryUsage >= 1_073_741_824 => round($this->memoryUsage / 1_073_741_824, 2) . ' GB',
            $this->memoryUsage >= 1_048_576     => round($this->memoryUsage / 1_048_576, 2) . ' MB',
            $this->memoryUsage >= 1024          => round($this->memoryUsage / 1024, 2) . ' KB',
            default                             => $this->memoryUsage . ' B',
        };
    }

    /**
     * Return stats as array for introspection / JSON serialization.
     *
     * @return array{hits: int, misses: int, writes: int, deletes: int, itemCount: int, memoryUsage: int, hitRate: float}
     */
    public function toArray(): array
    {
        return [
            'hits'        => $this->hits,
            'misses'      => $this->misses,
            'writes'      => $this->writes,
            'deletes'     => $this->deletes,
            'itemCount'   => $this->itemCount,
            'memoryUsage' => $this->memoryUsage,
            'hitRate'     => $this->hitRate,
        ];
    }
}
