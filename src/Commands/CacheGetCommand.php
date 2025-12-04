<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Commands;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Get a value from the cache
 * 
 * Usage:
 *   php ml cache:get user:123
 *   php ml cache:get user:123 --store=redis
 *   php ml cache:get user:123 --format=json
 */
#[CommandAttr('cache:get', 'Retrieve a value from cache')]
final class CacheGetCommand extends Command
{
    public function __construct(
        private CacheManager $cache
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $key = (is_array($argv) && isset($argv[2]) && is_string($argv[2]))
            ? $argv[2]
            : $this->ask('Enter cache key');

        if (!is_string($key) || empty($key)) {
            $this->error('No key specified');
            return self::FAILURE;
        }

        $args = array_slice($argv, 3);
        $options = $this->parseOptions($args);

        try {
            $cacheStore = $this->cache->store($options['store']);
            $storeName = $options['store'] ?? $this->cache->getDefaultDriver();

            $value = $cacheStore->get($key);

            if ($value === null) {
                $this->line("Key '{$key}' not found in store: {$storeName}");
                return self::SUCCESS;
            }

            $this->info("Key: {$key}");
            $this->line("Store: {$storeName}");
            $this->line('');

            if ($options['format'] === 'json') {
                $this->line(json_encode($value, JSON_PRETTY_PRINT));
            } else {
                $this->line('Value:');
                $this->displayValue($value);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to get value: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $args
     * @return array{store: string|null, format: string}
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'store' => null,
            'format' => 'text',
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--store=')) {
                $options['store'] = substr($arg, 8);
            } elseif (str_starts_with($arg, '--format=')) {
                $options['format'] = substr($arg, 9);
            }
        }

        return $options;
    }

    private function displayValue(mixed $value): void
    {
        if (is_bool($value)) {
            $this->line($value ? 'true' : 'false');
        } elseif (is_array($value)) {
            $this->line(print_r($value, true));
        } elseif (is_object($value)) {
            $this->line(print_r($value, true));
        } else {
            $this->line((string)$value);
        }
    }
}
