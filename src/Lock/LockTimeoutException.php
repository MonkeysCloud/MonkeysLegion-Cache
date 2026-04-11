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
 * Thrown when a lock cannot be acquired within the timeout.
 */
final class LockTimeoutException extends \RuntimeException
{
    public function __construct(string $name, int $seconds = 0)
    {
        parent::__construct(
            "Lock [{$name}] could not be acquired" .
            ($seconds > 0 ? " within {$seconds} seconds." : '.'),
        );
    }
}
