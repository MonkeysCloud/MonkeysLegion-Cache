<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache\Lock
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache\Lock;

/**
 * Lock state transitions.
 */
enum LockState: string
{
    case Acquired = 'acquired';
    case Released = 'released';
    case Expired  = 'expired';

    /**
     * Whether the lock is currently held.
     */
    public function isHeld(): bool
    {
        return $this === self::Acquired;
    }
}
