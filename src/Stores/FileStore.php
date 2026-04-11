<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache\Stores
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache\Stores;

use MonkeysLegion\Cache\CacheStats;
use MonkeysLegion\Cache\CacheStore;
use MonkeysLegion\Cache\Lock\FileLock;
use MonkeysLegion\Cache\Lock\LockInterface;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\PhpSerializer;

/**
 * File-based cache store with directory sharding and atomic writes.
 *
 * Uses PHP 8.4: final class, new-in-initializer.
 */
final class FileStore extends CacheStore
{
    private readonly string $directory;

    public function __construct(
        string $directory,
        string $prefix = '',
        CacheSerializerInterface $serializer = new PhpSerializer(),
        private readonly int $shardDepth = 2,
    ) {
        parent::__construct($prefix, $serializer);

        // Prevent path traversal: reject if the path contains '..' sequences
        if (str_contains($directory, '..')) {
            throw new \InvalidArgumentException(
                'Cache directory must not contain path traversal sequences (..).',
            );
        }

        $this->directory = rtrim($directory, '/');

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o755, true);
        }

        // Verify the resolved real path matches the intended directory
        $realPath = realpath($this->directory);

        if ($realPath !== false && $realPath !== $this->directory) {
            $this->directory = $realPath;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!file_exists($path)) {
            $this->statMisses++;
            return $default;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->statMisses++;
            return $default;
        }

        $payload = $this->serializer->unserialize($contents);

        if (!is_array($payload) || $this->isExpired($payload)) {
            @unlink($path);
            $this->statMisses++;
            return $default;
        }

        $this->statHits++;
        return $payload['value'];
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $seconds    = $this->ttlToSeconds($ttl);
        $expiration = $seconds !== null ? time() + $seconds : null;

        $payload = $this->serializer->serialize([
            'value'      => $value,
            'expiration' => $expiration,
            'createdAt'  => time(),
            'tags'       => [],
        ]);

        $path      = $this->path($key);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        // Atomic write: write to temp file then rename for crash safety
        $tmpPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
            @unlink($tmpPath);
            return false;
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        $this->statWrites++;
        return true;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        if (file_exists($path)) {
            $this->statDeletes++;
            return @unlink($path);
        }

        return true;
    }

    public function clear(): bool
    {
        $this->clearDirectory($this->directory);
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = (int) $this->get($key, 0);
        $new     = $current + $value;

        if ($this->set($key, $new)) {
            return $new;
        }

        return false;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function touch(string $key, \DateInterval|int $ttl): bool
    {
        $path = $this->path($key);

        if (!file_exists($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $payload = $this->serializer->unserialize($contents);

        if (!is_array($payload) || $this->isExpired($payload)) {
            @unlink($path);
            return false;
        }

        $seconds             = $this->ttlToSeconds($ttl);
        $payload['expiration'] = $seconds !== null ? time() + $seconds : null;

        return file_put_contents($path, $this->serializer->serialize($payload), LOCK_EX) !== false;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockInterface
    {
        return new FileLock($name, $seconds, $owner, $this->directory . '/.locks');
    }

    public function getStats(): CacheStats
    {
        $count = 0;
        $size  = 0;

        if (is_dir($this->directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && !str_contains($file->getPath(), '.locks')) {
                    $count++;
                    $size += $file->getSize();
                }
            }
        }

        return new CacheStats(
            hits:        $this->statHits,
            misses:      $this->statMisses,
            writes:      $this->statWrites,
            deletes:     $this->statDeletes,
            itemCount:   $count,
            memoryUsage: $size,
        );
    }

    /**
     * Garbage collection — remove all expired cache files.
     *
     * @return int Number of expired files removed.
     */
    public function gc(): int
    {
        $removed = 0;

        if (!is_dir($this->directory)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || str_contains($file->getPath(), '.locks')) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            try {
                $payload = $this->serializer->unserialize($contents);
            } catch (\Throwable) {
                @unlink($file->getPathname());
                $removed++;
                continue;
            }

            if (is_array($payload) && $this->isExpired($payload)) {
                @unlink($file->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    // ── Private ────────────────────────────────────────────────

    /**
     * Get the full file path for a cache key with directory sharding.
     */
    private function path(string $key): string
    {
        $hash  = hash('xxh128', $this->prepareKey($key));
        $parts = array_slice(str_split($hash, 2), 0, $this->shardDepth);

        return $this->directory . '/' . implode('/', $parts) . '/' . $hash;
    }

    /**
     * @param array{expiration: ?int} $payload
     */
    private function isExpired(array $payload): bool
    {
        return isset($payload['expiration']) && $payload['expiration'] !== null && time() >= $payload['expiration'];
    }

    private function clearDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \FilesystemIterator($directory);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->clearDirectory($item->getPathname());
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }
}
