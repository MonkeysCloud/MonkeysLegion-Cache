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
 * Atomic distributed lock contract.
 */
interface LockInterface
{
    /**
     * Attempt to acquire the lock.
     */
    public function acquire(int $ttl = 10): bool;

    /**
     * Release the lock (only if we are the owner).
     */
    public function release(): bool;

    /**
     * Force-release the lock regardless of ownership.
     */
    public function forceRelease(): bool;

    /**
     * Execute a callback within the lock scope.
     *
     * Acquires → runs callback → releases automatically.
     *
     * @throws LockTimeoutException If the lock cannot be acquired.
     */
    public function get(\Closure $callback, int $ttl = 10): mixed;

    /**
     * Block until the lock is acquired, then optionally run a callback.
     *
     * @param int           $seconds  Maximum seconds to wait.
     * @param \Closure|null $callback Optional callback to execute once acquired.
     *
     * @throws LockTimeoutException If the lock isn't acquired within $seconds.
     */
    public function block(int $seconds, ?\Closure $callback = null): mixed;

    /**
     * Return the unique owner token.
     */
    public function owner(): string;
}
