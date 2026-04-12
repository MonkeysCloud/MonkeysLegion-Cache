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
 * Cache event types.
 */
enum CacheEventType: string
{
    case Hit    = 'hit';
    case Miss   = 'miss';
    case Write  = 'write';
    case Delete = 'delete';
    case Flush  = 'flush';

    /**
     * Whether this event represents a read operation.
     */
    public function isRead(): bool
    {
        return match ($this) {
            self::Hit, self::Miss => true,
            default               => false,
        };
    }

    /**
     * Whether this event represents a mutation.
     */
    public function isMutation(): bool
    {
        return match ($this) {
            self::Write, self::Delete, self::Flush => true,
            default                                => false,
        };
    }
}
