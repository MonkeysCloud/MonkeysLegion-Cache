<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Commands;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Clear cache command
 * 
 * Usage:
 *   php ml cache:clear
 *   php ml cache:clear --store=redis
 *   php ml cache:clear --tags=users,posts
 */
#[CommandAttr('cache:clear', 'Clear the application cache')]
final class CacheClearCommand extends Command
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
        $tags = $options['tags'] ?? [];

        try {
            $cacheStore = $this->cache->store($store);
            $storeName = $store ?? $this->cache->getDefaultDriver();

            $this->info('Clearing cache...');

            if (!empty($tags)) {
                $cacheStore->tags($tags)->clear();
                $tagList = implode(', ', $tags);
                $this->info("✅  Cache cleared for tags: {$tagList} in store: {$storeName}");
            } else {
                $cacheStore->clear();
                $this->info("✅  Cache cleared successfully for store: {$storeName}");
            }

            $this->showStats($storeName);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to clear cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Parse command line options
     * 
     * @param array<int, string> $args
     * @return array{store: string|null, tags: array<int, string>}
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'store' => null,
            'tags' => [],
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--store=')) {
                $options['store'] = substr($arg, 8);
            } elseif (str_starts_with($arg, '--tags=')) {
                $tagString = substr($arg, 7);
                $options['tags'] = array_filter(array_map('trim', explode(',', $tagString)));
            }
        }

        return $options;
    }

    private function showStats(string $storeName): void
    {
        $config = $this->config['stores'][$storeName] ?? null;
        
        if (!$config) {
            return;
        }

        $driver = $config['driver'] ?? 'unknown';

        $this->line('');
        $this->line('Driver: ' . $driver);
        $this->line('Prefix: ' . ($config['prefix'] ?? 'none'));
    }
}
