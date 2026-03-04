<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Show cache statistics
 * 
 * Usage:
 *   php ml cache:stats
 *   php ml cache:stats --store=redis
 */
#[CommandAttr('cache:stats', 'Show cache statistics')]
final class CacheStatsCommand extends Command
{
    public function __construct(
        private CacheManager $cache,
        private array $config = []
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $args = array_slice($argv, 2);
        $store = $this->parseStore($args);

        try {
            $storeName = $store ?? $this->cache->getDefaultDriver();
            $config = $this->config['stores'][$storeName] ?? null;

            if (!$config) {
                $this->error("Store '{$storeName}' not found");
                return self::FAILURE;
            }

            $driver = $config['driver'] ?? 'unknown';

            $this->info("Cache Statistics: {$storeName}");
            $this->line(str_repeat('=', 60));
            $this->line('');
            $this->line("Driver: {$driver}");
            $this->line("Prefix: " . ($config['prefix'] ?? 'none'));
            $this->line('');

            match ($driver) {
                'redis' => $this->showRedisStats($storeName, $config),
                'file' => $this->showFileStats($config),
                'memcached' => $this->showMemcachedStats($storeName, $config),
                'array' => $this->showArrayStats($storeName),
                default => $this->line('No statistics available for this driver'),
            };

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to retrieve stats: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $args
     */
    private function parseStore(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--store=')) {
                return substr($arg, 8);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function showRedisStats(string $storeName, array $config): void
    {
        try {
            $store = $this->cache->store($storeName);
            
            // Check if store has getRedis method
            if (!method_exists($store, 'getRedis')) {
                $this->line('Redis connection: Not available');
                return;
            }

            $redis = $store->getRedis();
            $info = $redis->info();

            $this->info('Redis Statistics:');
            $this->line('  Connected: Yes');
            $this->line('  Version: ' . ($info['redis_version'] ?? 'unknown'));
            $this->line('  Total Keys: ' . $redis->dbSize());
            $this->line('  Memory Used: ' . $this->formatBytes((int)($info['used_memory'] ?? 0)));
            $this->line('  Connected Clients: ' . ($info['connected_clients'] ?? 0));
            
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 6379;
            $db = $config['database'] ?? 0;
            $this->line('  Connection: ' . "{$host}:{$port} (DB {$db})");
        } catch (\Exception $e) {
            $this->line('Redis connection: Failed');
            $this->line('  Error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function showFileStats(array $config): void
    {
        $path = $config['path'] ?? '';

        if (!is_dir($path)) {
            $this->line('Cache directory: Not found');
            $this->line("  Path: {$path}");
            return;
        }

        $size = 0;
        $count = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
                $count++;
            }
        }

        $this->info('File Cache Statistics:');
        $this->line("  Directory: {$path}");
        $this->line('  Total Files: ' . $count);
        $this->line('  Total Size: ' . $this->formatBytes($size));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function showMemcachedStats(string $storeName, array $config): void
    {
        try {
            $store = $this->cache->store($storeName);
            
            if (!method_exists($store, 'getMemcached')) {
                $this->line('Memcached connection: Not available');
                return;
            }

            $memcached = $store->getMemcached();
            $stats = $memcached->getStats();

            if (empty($stats)) {
                $this->line('No statistics available');
                return;
            }

            $firstServer = array_values($stats)[0];

            $this->info('Memcached Statistics:');
            $this->line('  Connected: Yes');
            $this->line('  Version: ' . ($firstServer['version'] ?? 'unknown'));
            $this->line('  Total Items: ' . ($firstServer['curr_items'] ?? 0));
            $this->line('  Memory Used: ' . $this->formatBytes((int)($firstServer['bytes'] ?? 0)));
            $this->line('  Hit Rate: ' . $this->calculateHitRate($firstServer));
            
            $servers = $config['servers'] ?? [];
            $this->line('  Servers: ' . count($servers));
        } catch (\Exception $e) {
            $this->line('Memcached connection: Failed');
            $this->line('  Error: ' . $e->getMessage());
        }
    }

    private function showArrayStats(string $storeName): void
    {
        $this->info('Array Cache Statistics:');
        $this->line('  Type: In-memory');
        $this->line('  Persistence: Session only');
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function calculateHitRate(array $stats): string
    {
        $hits = (int)($stats['get_hits'] ?? 0);
        $misses = (int)($stats['get_misses'] ?? 0);
        $total = $hits + $misses;

        if ($total === 0) {
            return 'N/A';
        }

        $rate = ($hits / $total) * 100;
        return sprintf('%.2f%%', $rate);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return sprintf("%.2f %s", $bytes / pow(1024, $power), $units[$power]);
    }
}
