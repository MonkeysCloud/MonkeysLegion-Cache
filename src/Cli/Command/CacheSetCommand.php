<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Cli\Command;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Set a value in the cache
 * 
 * Usage:
 *   php ml cache:set user:123 "John Doe"
 *   php ml cache:set config:debug true --ttl=3600
 *   php ml cache:set user:data '{"name":"John"}' --store=redis
 */
#[CommandAttr('cache:set', 'Store a value in cache')]
final class CacheSetCommand extends Command
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

        $value = (is_array($argv) && isset($argv[3]) && is_string($argv[3]))
            ? $argv[3]
            : $this->ask('Enter value');

        if (!is_string($key) || empty($key)) {
            $this->error('No key specified');
            return self::FAILURE;
        }

        $args = array_slice($argv, 4);
        $options = $this->parseOptions($args);

        try {
            // Parse value
            $parsedValue = $this->parseValue($value);

            $cacheStore = $this->cache->store($options['store']);
            $storeName = $options['store'] ?? $this->cache->getDefaultDriver();

            $cacheStore->set($key, $parsedValue, $options['ttl']);

            $ttlDisplay = $options['ttl'] ? "{$options['ttl']}s" : 'forever';
            $this->info("✅  Value stored successfully");
            $this->line("Key: {$key}");
            $this->line("Store: {$storeName}");
            $this->line("TTL: {$ttlDisplay}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to set value: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $args
     * @return array{store: string|null, ttl: int|null}
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'store' => null,
            'ttl' => null,
        ];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--store=')) {
                $options['store'] = substr($arg, 8);
            } elseif (str_starts_with($arg, '--ttl=')) {
                $options['ttl'] = (int)substr($arg, 6);
            }
        }

        return $options;
    }

    private function parseValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // Try to decode JSON
        if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Parse boolean
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // Parse null
        if ($value === 'null') {
            return null;
        }

        // Parse number
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        return $value;
    }
}
