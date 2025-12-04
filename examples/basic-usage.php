<?php

require_once __DIR__ . '/vendor/autoload.php';

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\Cache;

// Load configuration
$config = [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => __DIR__ . '/storage/cache',
            'prefix' => 'example_',
        ],
        'array' => [
            'driver' => 'array',
            'prefix' => 'example_',
        ],
    ],
];

// Create cache manager
$manager = new CacheManager($config);
Cache::setInstance($manager);

// Example 1: Basic Get/Set
echo "=== Basic Get/Set ===\n";
Cache::set('user:name', 'John Doe', 3600);
echo "Name: " . Cache::get('user:name') . "\n\n";

// Example 2: Remember Pattern
echo "=== Remember Pattern ===\n";
$users = Cache::remember('users:all', 3600, function() {
    echo "Computing expensive operation...\n";
    return ['John', 'Jane', 'Bob'];
});
echo "Users (first call): " . implode(', ', $users) . "\n";

$users = Cache::remember('users:all', 3600, function() {
    echo "This won't be called\n";
    return ['John', 'Jane', 'Bob'];
});
echo "Users (cached): " . implode(', ', $users) . "\n\n";

// Example 3: Increment/Decrement
echo "=== Increment/Decrement ===\n";
Cache::set('page:views', 0);
Cache::increment('page:views');
Cache::increment('page:views');
Cache::increment('page:views', 5);
echo "Page views: " . Cache::get('page:views') . "\n\n";

// Example 4: Multiple Operations
echo "=== Multiple Operations ===\n";
Cache::putMany([
    'product:1' => 'Laptop',
    'product:2' => 'Mouse',
    'product:3' => 'Keyboard',
], 3600);

$products = Cache::getMultiple(['product:1', 'product:2', 'product:3']);
foreach ($products as $key => $product) {
    echo "$key: $product\n";
}
echo "\n";

// Example 5: Cache Tagging
echo "=== Cache Tagging ===\n";
Cache::tags(['users', 'premium'])->set('user:1', 'Premium User 1', 3600);
Cache::tags(['users'])->set('user:2', 'Regular User 2', 3600);
Cache::tags(['products'])->set('product:featured', 'Featured Product', 3600);

echo "User 1: " . Cache::tags(['users', 'premium'])->get('user:1') . "\n";

// Clear all users cache
Cache::tags(['users'])->clear();
echo "After clearing 'users' tag:\n";
echo "User 1: " . (Cache::tags(['users', 'premium'])->get('user:1') ?? 'null') . "\n";
echo "Featured Product: " . Cache::tags(['products'])->get('product:featured') . "\n\n";

// Example 6: Pull (Get and Delete)
echo "=== Pull ===\n";
Cache::set('temp:token', 'abc123', 3600);
$token = Cache::pull('temp:token');
echo "Token: $token\n";
echo "Token exists: " . (Cache::has('temp:token') ? 'yes' : 'no') . "\n\n";

// Example 7: Add (Only if doesn't exist)
echo "=== Add ===\n";
$added1 = Cache::add('settings:theme', 'dark', 3600);
$added2 = Cache::add('settings:theme', 'light', 3600);
echo "First add: " . ($added1 ? 'success' : 'failed') . "\n";
echo "Second add: " . ($added2 ? 'success' : 'failed') . "\n";
echo "Theme: " . Cache::get('settings:theme') . "\n\n";

// Example 8: Using Helper Functions
echo "=== Helper Functions ===\n";
cache('helper:test', 'value from helper');
echo "Helper value: " . cache('helper:test') . "\n";

$result = cache_remember('helper:computed', 3600, fn() => 'computed value');
echo "Computed: $result\n\n";

// Example 9: Different Stores
echo "=== Different Stores ===\n";
Cache::store('file')->set('file:key', 'file value');
Cache::store('array')->set('array:key', 'array value');

echo "File store: " . Cache::store('file')->get('file:key') . "\n";
echo "Array store: " . Cache::store('array')->get('array:key') . "\n\n";

// Example 10: Forever (No expiration)
echo "=== Forever ===\n";
Cache::forever('config:version', '1.0.0');
echo "Version: " . Cache::get('config:version') . "\n\n";

echo "=== All Examples Complete ===\n";
