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
 * In-memory lock for testing and single-process scenarios.
 */
final class ArrayLock extends CacheLock
{
    /** @var array<string, array{owner: string, expires: int}> Global lock registry */
    private static array $locks = [];

    public function acquire(int $ttl = 10): bool
    {
        $this->cleanup();

        if (isset(self::$locks[$this->name])) {
            // Already locked by someone else
            if (self::$locks[$this->name]['owner'] !== $this->owner) {
                return false;
            }
        }

        self::$locks[$this->name] = [
            'owner'   => $this->owner,
            'expires' => time() + $ttl,
        ];

        $this->setState(LockState::Acquired);
        return true;
    }

    public function release(): bool
    {
        if (!isset(self::$locks[$this->name])) {
            return false;
        }

        if (self::$locks[$this->name]['owner'] !== $this->owner) {
            return false;
        }

        unset(self::$locks[$this->name]);
        $this->setState(LockState::Released);
        return true;
    }

    public function forceRelease(): bool
    {
        unset(self::$locks[$this->name]);
        $this->setState(LockState::Released);
        return true;
    }

    /**
     * Clean up expired locks.
     */
    private function cleanup(): void
    {
        foreach (self::$locks as $name => $data) {
            if (time() >= $data['expires']) {
                unset(self::$locks[$name]);
            }
        }
    }

    /**
     * Reset all locks (for testing).
     */
    public static function reset(): void
    {
        self::$locks = [];
    }
}
