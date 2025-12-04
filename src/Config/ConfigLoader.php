<?php

namespace MonkeysLegion\Cache\Config;

/**
 * Cache Configuration Loader
 * 
 * Loads cache configuration from .mlc files using MonkeysLegion-Mlc loader
 * Falls back to traditional PHP config if .mlc is not available
 */
class ConfigLoader
{
    private ?object $mlcConfig = null;
    private ?array $phpConfig = null;

    /**
     * Load configuration from .mlc or PHP files
     */
    public function load(string $configPath, ?object $mlcLoader = null): array
    {
        // Try to load from .mlc first if loader is available
        if ($mlcLoader !== null) {
            return $this->loadFromMlc($mlcLoader);
        }

        // Try to load from cache.mlc directly
        $mlcFile = $configPath . '/cache.mlc';
        if (file_exists($mlcFile)) {
            return $this->parseManualMlc($mlcFile);
        }

        // Fall back to traditional PHP config
        $phpFile = $configPath . '/cache.php';
        if (file_exists($phpFile)) {
            return require $phpFile;
        }

        throw new \RuntimeException('No cache configuration file found');
    }

    /**
     * Load configuration using MonkeysLegion-Mlc loader
     */
    private function loadFromMlc(object $mlcLoader): array
    {
        // Load the cache configuration
        $config = $mlcLoader->load(['cache']);

        // Convert to array format expected by CacheManager
        return [
            'default' => $config->get('cache.default', 'file'),
            'prefix' => $config->get('cache.prefix', 'ml_cache'),
            'stores' => $this->buildStores($config),
            'options' => [
                'serialize' => $config->get('cache.options.serialize', true),
                'compression' => $config->get('cache.options.compression', false),
            ],
            'ttl' => [
                'default' => $config->get('cache.ttl.default', 3600),
                'short' => $config->get('cache.ttl.short', 300),
                'medium' => $config->get('cache.ttl.medium', 1800),
                'long' => $config->get('cache.ttl.long', 86400),
                'week' => $config->get('cache.ttl.week', 604800),
            ],
            'tags' => [
                'enabled' => $config->get('cache.tags.enabled', true),
                'separator' => $config->get('cache.tags.separator', ':'),
            ],
            'features' => [
                'remember' => $config->get('cache.features.remember', true),
                'atomic' => $config->get('cache.features.atomic', true),
                'tagging' => $config->get('cache.features.tagging', true),
                'monitoring' => $config->get('cache.features.monitoring', false),
            ],
        ];
    }

    /**
     * Build stores configuration from MLC config
     */
    private function buildStores(object $config): array
    {
        $stores = [];
        $storeNames = ['file', 'redis', 'memcached', 'array'];

        foreach ($storeNames as $name) {
            $driver = $config->get("cache.stores.{$name}.driver");
            
            if ($driver === null) {
                continue;
            }

            $stores[$name] = match ($driver) {
                'file' => [
                    'driver' => 'file',
                    'path' => $config->get("cache.stores.{$name}.path", 'storage/cache'),
                    'prefix' => $config->get("cache.stores.{$name}.prefix", 'ml_cache'),
                ],
                'redis' => [
                    'driver' => 'redis',
                    'host' => $config->get("cache.stores.{$name}.host", '127.0.0.1'),
                    'port' => $config->get("cache.stores.{$name}.port", 6379),
                    'password' => $config->get("cache.stores.{$name}.password"),
                    'database' => $config->get("cache.stores.{$name}.database", 1),
                    'timeout' => $config->get("cache.stores.{$name}.timeout", 0.0),
                    'prefix' => $config->get("cache.stores.{$name}.prefix", 'ml_cache'),
                ],
                'memcached' => [
                    'driver' => 'memcached',
                    'persistent_id' => $config->get("cache.stores.{$name}.persistent_id"),
                    'prefix' => $config->get("cache.stores.{$name}.prefix", 'ml_cache'),
                    'servers' => $this->buildMemcachedServers($config, $name),
                ],
                'array' => [
                    'driver' => 'array',
                    'prefix' => $config->get("cache.stores.{$name}.prefix", 'ml_cache'),
                ],
                default => null,
            };
        }

        return $stores;
    }

    /**
     * Build Memcached servers array
     */
    private function buildMemcachedServers(object $config, string $storeName): array
    {
        $servers = [];
        $index = 0;

        while ($host = $config->get("cache.stores.{$storeName}.servers.{$index}.host")) {
            $servers[] = [
                'host' => $host,
                'port' => $config->get("cache.stores.{$storeName}.servers.{$index}.port", 11211),
                'weight' => $config->get("cache.stores.{$storeName}.servers.{$index}.weight", 100),
            ];
            $index++;
        }

        // Default server if none configured
        if (empty($servers)) {
            $servers[] = [
                'host' => '127.0.0.1',
                'port' => 11211,
                'weight' => 100,
            ];
        }

        return $servers;
    }

    /**
     * Parse .mlc file manually (basic parser for standalone usage)
     */
    private function parseManualMlc(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Parse key = value
            if (strpos($line, '=') !== false) {
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                
                // Handle env() helper
                if (preg_match('/env\("([^"]+)"(?:,\s*(.+))?\)/', $value, $matches)) {
                    $envKey = $matches[1];
                    $default = $matches[2] ?? null;
                    $value = $this->getEnvValue($envKey, $default);
                } else {
                    $value = $this->parseValue($value);
                }

                // Set nested value
                $this->setNestedValue($config, $key, $value);
            }
        }

        return $this->transformMlcToPhp($config);
    }

    /**
     * Get environment variable value
     */
    private function getEnvValue(string $key, ?string $default): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        
        if ($value === false || $value === '') {
            return $this->parseValue($default);
        }

        return $this->parseValue($value);
    }

    /**
     * Parse value to appropriate type
     */
    private function parseValue(?string $value): mixed
    {
        if ($value === null || $value === 'null') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        // Remove quotes
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        // Try numeric
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        return $value;
    }

    /**
     * Set nested array value using dot notation
     */
    private function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }

    /**
     * Transform MLC config structure to PHP config format
     */
    private function transformMlcToPhp(array $mlcConfig): array
    {
        $cache = $mlcConfig['cache'] ?? [];
        
        return [
            'default' => $cache['default'] ?? 'file',
            'prefix' => $cache['prefix'] ?? 'ml_cache',
            'stores' => $cache['stores'] ?? [],
            'options' => $cache['options'] ?? [],
            'ttl' => $cache['ttl'] ?? [],
            'tags' => $cache['tags'] ?? [],
            'features' => $cache['features'] ?? [],
        ];
    }
}
