<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * List all configured cache stores
 * 
 * Usage:
 *   php ml cache:stores
 */
#[CommandAttr('cache:stores', 'List all configured cache stores')]
final class CacheStoresCommand extends Command
{
    public function __construct(
        private CacheManager $cache,
        private array $config = []
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $stores = $this->config['stores'] ?? [];

        if (empty($stores)) {
            $this->line('No cache stores configured');
            return self::SUCCESS;
        }

        $default = $this->cache->getDefaultDriver();

        $this->info('Configured Cache Stores');
        $this->line(str_repeat('=', 60));
        $this->line('');

        foreach ($stores as $name => $config) {
            $driver = $config['driver'] ?? 'unknown';
            $isDefault = $name === $default ? ' [DEFAULT]' : '';

            $this->info("Store: {$name}{$isDefault}");
            $this->line("  Driver: {$driver}");
            $this->line("  Prefix: " . ($config['prefix'] ?? 'none'));

            if ($driver === 'file') {
                $this->line("  Path: " . ($config['path'] ?? 'not set'));
            } elseif ($driver === 'redis') {
                $host = $config['host'] ?? '127.0.0.1';
                $port = $config['port'] ?? 6379;
                $this->line("  Connection: {$host}:{$port}");
                $this->line("  Database: " . ($config['database'] ?? 0));
            } elseif ($driver === 'memcached') {
                $servers = $config['servers'] ?? [];
                if (!empty($servers)) {
                    $server = $servers[0];
                    $host = $server['host'] ?? '127.0.0.1';
                    $port = $server['port'] ?? 11211;
                    $this->line("  Server: {$host}:{$port}");
                    if (count($servers) > 1) {
                        $this->line("  Servers: " . count($servers) . " total");
                    }
                }
            }

            $this->line('');
        }

        $this->info('Total stores: ' . count($stores));

        return self::SUCCESS;
    }
}
