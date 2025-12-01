<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Commands;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

/**
 * Display cache configuration
 * 
 * Usage:
 *   php ml cache:config
 *   php ml cache:config --format=json
 */
#[CommandAttr('cache:config', 'Display cache configuration')]
final class CacheConfigCommand extends Command
{
    public function __construct(
        private array $config = []
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $args = array_slice($argv, 2);
        $format = $this->parseFormat($args);

        if ($format === 'json') {
            $this->line(json_encode($this->config, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Cache Configuration');
        $this->line(str_repeat('=', 60));
        $this->line('');

        // Default driver
        $default = $this->config['default'] ?? 'none';
        $this->info('Default Driver: ' . $default);
        $this->line('');

        // Global prefix
        if (isset($this->config['prefix'])) {
            $this->line('Global Prefix: ' . $this->config['prefix']);
            $this->line('');
        }

        // Stores
        $stores = $this->config['stores'] ?? [];
        
        if (!empty($stores)) {
            $this->info('Configured Stores:');
            foreach ($stores as $name => $config) {
                $this->displayStore($name, $config);
            }
        }

        // Options
        if (isset($this->config['options'])) {
            $this->line('');
            $this->info('Options:');
            foreach ($this->config['options'] as $key => $value) {
                $valueStr = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
                $this->line("  {$key}: {$valueStr}");
            }
        }

        // TTL presets
        if (isset($this->config['ttl'])) {
            $this->line('');
            $this->info('TTL Presets:');
            foreach ($this->config['ttl'] as $key => $seconds) {
                $this->line("  {$key}: {$seconds}s");
            }
        }

        // Features
        if (isset($this->config['features'])) {
            $this->line('');
            $this->info('Features:');
            foreach ($this->config['features'] as $key => $enabled) {
                $status = $enabled ? '✓' : '✗';
                $this->line("  {$status} {$key}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, string> $args
     */
    private function parseFormat(array $args): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--format=')) {
                return substr($arg, 9);
            }
        }
        return 'text';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function displayStore(string $name, array $config): void
    {
        $driver = $config['driver'] ?? 'unknown';
        $this->line('');
        $this->line("  Store: {$name}");
        $this->line("    Driver: {$driver}");
        $this->line("    Prefix: " . ($config['prefix'] ?? 'none'));

        if ($driver === 'file') {
            $this->line("    Path: " . ($config['path'] ?? 'not set'));
        } elseif ($driver === 'redis') {
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 6379;
            $db = $config['database'] ?? 0;
            $this->line("    Connection: {$host}:{$port}");
            $this->line("    Database: {$db}");
            $this->line("    Timeout: " . ($config['timeout'] ?? 0) . 's');
        } elseif ($driver === 'memcached') {
            $servers = $config['servers'] ?? [];
            $this->line("    Servers: " . count($servers));
            if (!empty($servers)) {
                foreach ($servers as $idx => $server) {
                    $host = $server['host'] ?? '127.0.0.1';
                    $port = $server['port'] ?? 11211;
                    $weight = $server['weight'] ?? 100;
                    $this->line("      Server {$idx}: {$host}:{$port} (weight: {$weight})");
                }
            }
            if (isset($config['persistent_id'])) {
                $this->line("    Persistent ID: " . $config['persistent_id']);
            }
        }
    }
}
