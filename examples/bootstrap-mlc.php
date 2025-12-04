<?php

/**
 * Bootstrap Cache with MonkeysLegion-Mlc Configuration
 * 
 * This example shows how to initialize the cache system using .mlc configuration files
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\Cache;
use MonkeysLegion\Cache\Config\ConfigLoader;

// Method 1: Using MonkeysLegion-Mlc Loader (if package is installed)
if (class_exists('MonkeysLegion\Mlc\Loader')) {
    
    // Create MLC Parser and Loader
    $parser = new \MonkeysLegion\Mlc\Parser();
    $loader = new \MonkeysLegion\Mlc\Loader(
        $parser,
        __DIR__ . '/../config',  // Config directory
        __DIR__ . '/../'         // .env directory
    );
    
    // Load cache configuration using MLC
    $configLoader = new ConfigLoader();
    $config = $configLoader->load(__DIR__ . '/../config', $loader);
    
    echo "✓ Loaded configuration from cache.mlc\n";
    
} else {
    
    // Method 2: Fallback to manual .mlc parsing or PHP config
    $configLoader = new ConfigLoader();
    $config = $configLoader->load(__DIR__ . '/../config');
    
    echo "✓ Loaded configuration (MLC package not installed, using fallback)\n";
}

// Create cache manager
$cacheManager = new CacheManager($config);

// Set the facade instance
Cache::setInstance($cacheManager);

echo "✓ Cache system initialized\n";
echo "  Default driver: " . $config['default'] . "\n";
echo "  Prefix: " . $config['prefix'] . "\n";
echo "\n";

// Test the cache
echo "Testing cache operations...\n";

// Set a value
Cache::set('mlc.test', 'Hello from MLC!', 60);
echo "✓ Set test value\n";

// Get the value
$value = Cache::get('mlc.test');
echo "✓ Retrieved value: {$value}\n";

// Test remember pattern
$computed = Cache::remember('mlc.computed', 60, function() {
    echo "  Computing expensive operation...\n";
    return 'Computed result!';
});
echo "✓ Remember pattern: {$computed}\n";

// Second call should be cached
$cached = Cache::remember('mlc.computed', 60, function() {
    echo "  This won't be called\n";
    return 'Computed result!';
});
echo "✓ Cached result: {$cached}\n";

// Show stores
echo "\nAvailable stores:\n";
foreach ($config['stores'] as $name => $storeConfig) {
    $driver = $storeConfig['driver'] ?? 'unknown';
    echo "  - {$name} ({$driver})\n";
}

// Test with tags if enabled
if ($config['tags']['enabled'] ?? false) {
    echo "\nTesting cache tags...\n";
    Cache::tags(['test'])->set('mlc.tagged', 'Tagged value');
    $tagged = Cache::tags(['test'])->get('mlc.tagged');
    echo "✓ Tagged value: {$tagged}\n";
}

// Show features
echo "\nEnabled features:\n";
foreach ($config['features'] ?? [] as $feature => $enabled) {
    $status = $enabled ? '✓' : '✗';
    echo "  {$status} {$feature}\n";
}

echo "\n✓ Cache bootstrap complete!\n";
