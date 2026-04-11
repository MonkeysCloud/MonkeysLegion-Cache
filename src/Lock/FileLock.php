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
 * File-based lock using flock() for single-server scenarios.
 */
final class FileLock extends CacheLock
{
    private readonly string $lockDir;

    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(
        string $name,
        int $seconds = 0,
        ?string $owner = null,
        string $lockDir = '/tmp/ml-cache-locks',
    ) {
        parent::__construct($name, $seconds, $owner);

        $this->lockDir = rtrim($lockDir, '/');

        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0o755, true);
        }
    }

    public function acquire(int $ttl = 10): bool
    {
        $path = $this->lockPath();

        $this->handle = @fopen($path, 'c');

        if ($this->handle === false) {
            $this->handle = null;
            return false;
        }

        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }

        // Write owner + expiration
        ftruncate($this->handle, 0);
        fwrite($this->handle, json_encode([
            'owner'   => $this->owner,
            'expires' => time() + $ttl,
        ]));
        fflush($this->handle);

        $this->setState(LockState::Acquired);
        return true;
    }

    public function release(): bool
    {
        if ($this->handle === null) {
            return false;
        }

        // Verify we are the owner
        $path = $this->lockPath();

        if (file_exists($path)) {
            $data = @json_decode(@file_get_contents($path) ?: '', true);

            if (is_array($data) && ($data['owner'] ?? '') !== $this->owner) {
                return false;
            }
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        @unlink($path);

        $this->setState(LockState::Released);
        return true;
    }

    public function forceRelease(): bool
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }

        @unlink($this->lockPath());

        $this->setState(LockState::Released);
        return true;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            @unlink($this->lockPath());
        }
    }

    private function lockPath(): string
    {
        return $this->lockDir . '/' . hash('xxh128', $this->name) . '.lock';
    }
}
