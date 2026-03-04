<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Warm the cache with predefined data
 * 
 * Usage:
 *   php ml cache:warm
 *   php ml cache:warm --store=redis
 */
#[CommandAttr('cache:warm', 'Warm the cache with predefined data')]
final class CacheWarmCommand extends Command
{
    public function __construct(
        private CacheManager $cache
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
            $cacheStore = $this->cache->store($store);

            $this->info("Warming cache for store: {$storeName}");
            $this->line('');

            $data = $this->getWarmupData();

            if (empty($data)) {
                $this->line('No warmup data defined');
                return self::SUCCESS;
            }

            $count = 0;
            foreach ($data as $key => $config) {
                $value = $config['value'] ?? null;
                $ttl = $config['ttl'] ?? null;

                if ($value !== null) {
                    $cacheStore->set($key, $value, $ttl);
                    $ttlDisplay = $ttl ? " (TTL: {$ttl}s)" : '';
                    $this->line("  ✓ Cached: {$key}{$ttlDisplay}");
                    $count++;
                }
            }

            $this->line('');
            $this->info("✅  Cache warmed with {$count} entries");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to warm cache: ' . $e->getMessage());
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
     * Get warmup data - override this method to customize
     * 
     * @return array<string, array{value: mixed, ttl?: int}>
     */
    protected function getWarmupData(): array
    {
        // Example warmup data - customize for your application
        return [
            'app:name' => [
                'value' => 'MonkeysLegion',
                'ttl' => 3600,
            ],
            'app:version' => [
                'value' => '1.0.0',
                'ttl' => 3600,
            ],
            'config:cache_enabled' => [
                'value' => true,
            ],
        ];
    }
}
