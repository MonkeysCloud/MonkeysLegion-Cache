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
 * Base lock implementation with asymmetric visibility (PHP 8.4).
 *
 * Subclasses implement acquire/release per backend.
 */
abstract class CacheLock implements LockInterface
{
    /**
     * Owner token — publicly readable, privately writable.
     */
    public private(set) string $owner;

    /**
     * Current lock state — publicly readable, privately writable.
     */
    public private(set) LockState $state = LockState::Released;

    public function __construct(
        protected readonly string $name,
        protected readonly int $seconds,
        ?string $owner = null,
    ) {
        $this->owner = $owner ?? bin2hex(random_bytes(16));
    }

    /**
     * Execute a callback within the lock scope.
     *
     * Acquires → runs callback → releases automatically.
     */
    public function get(\Closure $callback, int $ttl = 10): mixed
    {
        if (!$this->acquire($ttl)) {
            throw new LockTimeoutException($this->name, 0);
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Block until the lock is acquired, then optionally run a callback.
     */
    public function block(int $seconds, ?\Closure $callback = null): mixed
    {
        $start = time();

        while (!$this->acquire($this->seconds ?: $seconds)) {
            if (time() - $start >= $seconds) {
                throw new LockTimeoutException($this->name, $seconds);
            }

            usleep(50_000); // 50ms polling
        }

        if ($callback !== null) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Set internal state (for subclasses).
     */
    protected function setState(LockState $state): void
    {
        $this->state = $state;
    }
}
