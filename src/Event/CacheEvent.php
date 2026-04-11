<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache\Event
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache\Event;

/**
 * Immutable cache event value object.
 *
 * Uses PHP 8.4: asymmetric visibility + property hooks for computed `summary`.
 */
final class CacheEvent
{
    public private(set) float $timestamp;

    public function __construct(
        public private(set) CacheEventType $type,
        public private(set) string         $key,
        public private(set) string         $store    = 'default',
        public private(set) float          $duration = 0.0,
        /** @var list<string>|null */
        public private(set) ?array         $tags     = null,
    ) {
        $this->timestamp = microtime(true);
    }

    /**
     * Human-readable event summary.
     */
    public string $summary {
        get => sprintf(
            '[%s] %s: %s (%.2fμs)',
            $this->store,
            $this->type->value,
            $this->key,
            $this->duration,
        );
    }

    /**
     * Export to array for JSON serialization / telemetry.
     *
     * @return array{type: string, key: string, store: string, duration: float, tags: ?list<string>, timestamp: float}
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type->value,
            'key'       => $this->key,
            'store'     => $this->store,
            'duration'  => $this->duration,
            'tags'      => $this->tags,
            'timestamp' => $this->timestamp,
        ];
    }
}
