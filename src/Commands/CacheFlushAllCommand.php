<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Commands;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Flush all cache stores
 * 
 * Usage:
 *   php ml cache:flush-all
 *   php ml cache:flush-all --force
 */
#[CommandAttr('cache:flush-all', 'Flush all configured cache stores')]
final class CacheFlushAllCommand extends Command
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
        $force = in_array('--force', $args, true);

        $stores = $this->config['stores'] ?? [];

        if (empty($stores)) {
            $this->line('No cache stores configured');
            return self::SUCCESS;
        }

        if (!$force) {
            $this->line('');
            $this->line('⚠️  Warning: This will flush ALL cache stores:');
            foreach (array_keys($stores) as $storeName) {
                $this->line("  - {$storeName}");
            }
            $this->line('');

            $confirm = $this->ask('Are you sure? (yes/no)');
            
            if (!is_string($confirm) || strtolower(trim($confirm)) !== 'yes') {
                $this->line('Cancelled');
                return self::SUCCESS;
            }
        }

        $this->info('Flushing all cache stores...');
        $this->line('');

        $flushed = 0;
        $failed = 0;

        foreach (array_keys($stores) as $storeName) {
            try {
                $this->cache->store($storeName)->clear();
                $this->line("  ✓ Flushed: {$storeName}");
                $flushed++;
            } catch (\Exception $e) {
                $this->line("  ✗ Failed: {$storeName} - " . $e->getMessage());
                $failed++;
            }
        }

        $this->line('');

        if ($failed === 0) {
            $this->info("✅  All stores flushed successfully ({$flushed} stores)");
            return self::SUCCESS;
        } else {
            $this->error("⚠️  Some stores failed. Flushed: {$flushed}, Failed: {$failed}");
            return self::FAILURE;
        }
    }
}
