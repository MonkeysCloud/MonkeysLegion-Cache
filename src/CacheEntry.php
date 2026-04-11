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
 * Immutable value object wrapping cached data with metadata.
 *
 * Uses PHP 8.4 property hooks for computed properties (isExpired,
 * remainingTtl, age) and asymmetric visibility for safe public access.
 *
 * Note: Cannot be `readonly class` because hooked properties are
 * incompatible with readonly. Uses asymmetric visibility instead.
 */
final class CacheEntry
{
    public private(set) int $createdAt;

    public function __construct(
        public private(set) mixed $value,
        public private(set) ?int  $expiresAt = null,
        ?int                      $createdAt = null,
        /** @var list<string> */
        public private(set) array $tags      = [],
        public private(set) int   $hits      = 0,
    ) {
        $this->createdAt = $createdAt ?? time();
    }

    /**
     * Whether this entry has expired.
     */
    public bool $isExpired {
        get => $this->expiresAt !== null && time() >= $this->expiresAt;
    }

    /**
     * Seconds remaining until expiration, or null if no TTL.
     */
    public ?int $remainingTtl {
        get => $this->expiresAt !== null
            ? max(0, $this->expiresAt - time())
            : null;
    }

    /**
     * Seconds since this entry was created.
     */
    public int $age {
        get => time() - $this->createdAt;
    }

    /**
     * Whether this entry should be considered for probabilistic early refresh.
     *
     * Uses probability: as remaining TTL approaches zero,
     * the chance of early refresh increases. The beta parameter controls
     * the aggressiveness (higher beta = earlier refresh).
     *
     * @param float $beta Stampede protection factor (1.0 = default).
     */
    public function shouldRefresh(float $beta = 1.0): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $remaining = $this->expiresAt - time();
        $totalTtl  = $this->expiresAt - $this->createdAt;

        if ($totalTtl <= 0 || $remaining <= 0) {
            return true;
        }

        // log(random) * beta * totalTtl > remaining → probabilistic early refresh
        return -$beta * $totalTtl * log(random_int(1, PHP_INT_MAX) / PHP_INT_MAX) >= $remaining;
    }

    /**
     * Create a new entry with an incremented hit counter.
     */
    public function withHit(): self
    {
        return new self(
            value:     $this->value,
            expiresAt: $this->expiresAt,
            createdAt: $this->createdAt,
            tags:      $this->tags,
            hits:      $this->hits + 1,
        );
    }

    /**
     * Export to an array for serialization.
     *
     * @return array{value: mixed, expiresAt: ?int, createdAt: int, tags: list<string>, hits: int}
     */
    public function toArray(): array
    {
        return [
            'value'     => $this->value,
            'expiresAt' => $this->expiresAt,
            'createdAt' => $this->createdAt,
            'tags'      => $this->tags,
            'hits'      => $this->hits,
        ];
    }

    /**
     * Reconstruct from a serialized array.
     *
     * @param array{value: mixed, expiresAt: ?int, createdAt: int, tags: list<string>, hits: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            value:     $data['value'],
            expiresAt: $data['expiresAt'] ?? null,
            createdAt: $data['createdAt'] ?? time(),
            tags:      $data['tags'] ?? [],
            hits:      $data['hits'] ?? 0,
        );
    }
}
