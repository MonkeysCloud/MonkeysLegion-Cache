<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Forget (delete) specific cache keys
 * 
 * Usage:
 *   php ml cache:forget user:123
 *   php ml cache:forget user:123,user:456 --store=redis
 */
#[CommandAttr('cache:forget', 'Remove specific cache keys')]
final class CacheForgetCommand extends Command
{
    public function __construct(
        private CacheManager $cache
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $keysArg = (is_array($argv) && isset($argv[2]) && is_string($argv[2]))
            ? $argv[2]
            : $this->ask('Enter cache key(s) to forget (comma-separated)');

        if (!is_string($keysArg) || empty($keysArg)) {
            $this->error('No keys specified');
            return self::FAILURE;
        }

        $keys = array_filter(array_map('trim', explode(',', $keysArg)));
        $args = array_slice($argv, 3);
        $store = $this->parseStore($args);

        try {
            $cacheStore = $this->cache->store($store);
            $storeName = $store ?? $this->cache->getDefaultDriver();

            $this->info("Forgetting keys from store: {$storeName}");

            $deleted = 0;
            foreach ($keys as $key) {
                if ($cacheStore->delete($key)) {
                    $this->line("  ✓ Deleted: {$key}");
                    $deleted++;
                } else {
                    $this->line("  ✗ Not found: {$key}");
                }
            }

            $this->line('');
            $this->info("✅  Deleted {$deleted} of " . count($keys) . " keys");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to forget keys: ' . $e->getMessage());
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
}
