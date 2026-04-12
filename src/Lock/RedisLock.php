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
 * @requires  ext-redis
 */

namespace MonkeysLegion\Cache\Lock;

/**
 * Redis-based distributed lock using SETNX + Lua release.
 *
 * Truly atomic: only the owner who acquired the lock can release it
 * (verified via Lua script on the Redis server).
 */
final class RedisLock extends CacheLock
{
    /**
     * Lua script for safe release: only delete if the value matches the owner.
     */
    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        else
            return 0
        end
        LUA;

    public function __construct(
        private readonly \Redis $redis,
        string $name,
        int $seconds = 0,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(int $ttl = 10): bool
    {
        $key = $this->lockKey();

        if ($ttl > 0) {
            $result = $this->redis->set($key, $this->owner, ['NX', 'EX' => $ttl]);
        } else {
            $result = $this->redis->setnx($key, $this->owner);
        }

        if ($result) {
            $this->setState(LockState::Acquired);
            return true;
        }

        return false;
    }

    public function release(): bool
    {
        $result = $this->redis->eval(
            self::RELEASE_SCRIPT,
            [$this->lockKey(), $this->owner],
            1,
        );

        if ($result) {
            $this->setState(LockState::Released);
            return true;
        }

        return false;
    }

    public function forceRelease(): bool
    {
        $this->redis->del($this->lockKey());
        $this->setState(LockState::Released);
        return true;
    }

    private function lockKey(): string
    {
        return 'lock:' . $this->name;
    }
}
