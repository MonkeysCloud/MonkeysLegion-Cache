<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Monitor cache in real-time
 * 
 * Usage:
 *   php ml cache:monitor
 *   php ml cache:monitor --store=redis
 *   php ml cache:monitor --interval=5
 */
#[CommandAttr('cache:monitor', 'Monitor cache performance in real-time')]
final class CacheMonitorCommand extends Command
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
        
        $options = $this->parseOptions($args);
        $store = $options['store'] ?? null;
        $interval = $options['interval'];

        try {
            $storeName = $store ?? $this->cache->getDefaultDriver();
            $config = $this->config['stores'][$storeName] ?? null;

            if (!$config) {
                $this->error("Store '{$storeName}' not found");
                return self::FAILURE;
            }

            $driver = $config['driver'] ?? 'unknown';

            $this->info("Monitoring cache store: {$storeName} (Press Ctrl+C to stop)");
            $this->line("Refresh interval: {$interval}s");
            $this->line('');

            while (true) {
                $this->clearScreen();
                $this->displayHeader($storeName, $driver);
                
                match ($driver) {
                    'redis' => $this->displayRedisStats($storeName),
                    'file' => $this->displayFileStats($config),
                    'memcached' => $this->displayMemcachedStats($storeName),
                    'array' => $this->displayArrayStats(),
                    default => $this->line('Monitoring not available for this driver'),
                };

                $this->line('');
                $this->line('Last updated: ' . date('Y-m-d H:i:s'));

                sleep($interval);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Monitor failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $args
     * @return array{store: string|null, interval: int}
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'store' => null,
            'interval' => 3,
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--store=')) {
                $options['store'] = substr($arg, 8);
            } elseif (str_starts_with($arg, '--interval=')) {
                $options['interval'] = max(1, (int)substr($arg, 11));
            }
        }

        return $options;
    }

    private function clearScreen(): void
    {
        // ANSI escape code to clear screen
        echo "\033[2J\033[H";
    }

    private function displayHeader(string $storeName, string $driver): void
    {
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("  Cache Monitor: {$storeName}");
        $this->line("  Driver: {$driver}");
        $this->info('═══════════════════════════════════════════════════════════');
        $this->line('');
    }

    private function displayRedisStats(string $storeName): void
    {
        try {
            $store = $this->cache->store($storeName);
            
            if (!method_exists($store, 'getRedis')) {
                $this->line('Redis connection: Not available');
                return;
            }

            $redis = $store->getRedis();
            $info = $redis->info();

            $this->info('Connection Status:');
            $this->line('  Status: Connected');
            $this->line('  Version: ' . ($info['redis_version'] ?? 'unknown'));
            $this->line('');

            $this->info('Memory:');
            $memory = (int)($info['used_memory'] ?? 0);
            $maxMemory = (int)($info['maxmemory'] ?? 0);
            $this->line('  Used: ' . $this->formatBytes($memory));
            if ($maxMemory > 0) {
                $percentage = ($memory / $maxMemory) * 100;
                $this->line('  Max: ' . $this->formatBytes($maxMemory));
                $this->line('  Usage: ' . sprintf('%.2f%%', $percentage));
            }
            $this->line('');

            $this->info('Keys:');
            $this->line('  Total: ' . $redis->dbSize());
            $this->line('');

            $this->info('Clients:');
            $this->line('  Connected: ' . ($info['connected_clients'] ?? 0));
            $this->line('  Blocked: ' . ($info['blocked_clients'] ?? 0));
            $this->line('');

            $this->info('Stats:');
            $this->line('  Commands: ' . number_format((int)($info['total_commands_processed'] ?? 0)));
            $this->line('  Connections: ' . number_format((int)($info['total_connections_received'] ?? 0)));
            
            $hits = (int)($info['keyspace_hits'] ?? 0);
            $misses = (int)($info['keyspace_misses'] ?? 0);
            $total = $hits + $misses;
            if ($total > 0) {
                $hitRate = ($hits / $total) * 100;
                $this->line('  Hit Rate: ' . sprintf('%.2f%%', $hitRate));
            }
        } catch (\Exception $e) {
            $this->error('Failed to retrieve stats: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function displayFileStats(array $config): void
    {
        $path = $config['path'] ?? '';

        if (!is_dir($path)) {
            $this->line('Cache directory not found');
            return;
        }

        $size = 0;
        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }

            $this->info('Directory:');
            $this->line("  Path: {$path}");
            $this->line('');

            $this->info('Files:');
            $this->line("  Total: {$count}");
            $this->line('  Size: ' . $this->formatBytes($size));
        } catch (\Exception $e) {
            $this->error('Failed to scan directory: ' . $e->getMessage());
        }
    }

    private function displayMemcachedStats(string $storeName): void
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

            $this->info('Connection Status:');
            $this->line('  Status: Connected');
            $this->line('  Version: ' . ($firstServer['version'] ?? 'unknown'));
            $this->line('');

            $this->info('Memory:');
            $bytes = (int)($firstServer['bytes'] ?? 0);
            $limit = (int)($firstServer['limit_maxbytes'] ?? 0);
            $this->line('  Used: ' . $this->formatBytes($bytes));
            if ($limit > 0) {
                $percentage = ($bytes / $limit) * 100;
                $this->line('  Limit: ' . $this->formatBytes($limit));
                $this->line('  Usage: ' . sprintf('%.2f%%', $percentage));
            }
            $this->line('');

            $this->info('Items:');
            $this->line('  Current: ' . number_format((int)($firstServer['curr_items'] ?? 0)));
            $this->line('  Total: ' . number_format((int)($firstServer['total_items'] ?? 0)));
            $this->line('');

            $this->info('Stats:');
            $hits = (int)($firstServer['get_hits'] ?? 0);
            $misses = (int)($firstServer['get_misses'] ?? 0);
            $this->line('  Gets: ' . number_format($hits + $misses));
            $this->line('  Hits: ' . number_format($hits));
            $this->line('  Misses: ' . number_format($misses));
            
            $total = $hits + $misses;
            if ($total > 0) {
                $hitRate = ($hits / $total) * 100;
                $this->line('  Hit Rate: ' . sprintf('%.2f%%', $hitRate));
            }
        } catch (\Exception $e) {
            $this->error('Failed to retrieve stats: ' . $e->getMessage());
        }
    }

    private function displayArrayStats(): void
    {
        $this->info('Array Cache:');
        $this->line('  Type: In-memory');
        $this->line('  Persistence: Session only');
        $this->line('  Monitoring: Limited for array driver');
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return sprintf("%.2f %s", $bytes / pow(1024, $power), $units[$power]);
    }
}
