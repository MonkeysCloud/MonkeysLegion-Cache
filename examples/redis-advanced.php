<?php

/**
 * Redis Cache Example
 * 
 * This example demonstrates Redis-specific features and optimizations
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\Cache;

// Configuration for Redis
$config = [
    'default' => 'redis',
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 1,
            'prefix' => 'myapp_',
        ],
    ],
];

// Initialize cache
$manager = new CacheManager($config);
Cache::setInstance($manager);

echo "=== Redis Cache Examples ===\n\n";

// Example 1: Atomic Counters
echo "1. Atomic Counters:\n";
Cache::set('page:views', 0);
Cache::increment('page:views');
Cache::increment('page:views');
Cache::increment('page:views', 5);
echo "   Page views: " . Cache::get('page:views') . "\n\n";

// Example 2: Session Storage
echo "2. Session Storage:\n";
$sessionId = 'sess_' . bin2hex(random_bytes(16));
$sessionData = [
    'user_id' => 123,
    'username' => 'john_doe',
    'email' => 'john@example.com',
    'last_activity' => time(),
];
Cache::set("session:{$sessionId}", $sessionData, 3600); // 1 hour TTL
echo "   Session stored: {$sessionId}\n";
$retrieved = Cache::get("session:{$sessionId}");
echo "   User: {$retrieved['username']}\n\n";

// Example 3: Rate Limiting
echo "3. Rate Limiting:\n";
function checkRateLimit(string $userId, int $maxRequests = 10, int $window = 60): bool {
    $key = "ratelimit:{$userId}";
    $requests = (int) Cache::get($key, 0);
    
    if ($requests >= $maxRequests) {
        return false;
    }
    
    Cache::increment($key);
    
    // Set expiration on first request
    if ($requests === 0) {
        Cache::set($key, 1, $window);
    }
    
    return true;
}

for ($i = 1; $i <= 12; $i++) {
    $allowed = checkRateLimit('user:123', 10, 60);
    echo "   Request {$i}: " . ($allowed ? 'Allowed' : 'Rate limited') . "\n";
}
echo "\n";

// Example 4: Caching Database Queries
echo "4. Database Query Caching:\n";
function getUser(int $userId) {
    return Cache::remember("user:{$userId}", 3600, function() use ($userId) {
        // Simulate database query
        echo "   [DB Query] Fetching user {$userId}...\n";
        return [
            'id' => $userId,
            'name' => 'User ' . $userId,
            'email' => "user{$userId}@example.com",
        ];
    });
}

echo "   First call:\n";
$user = getUser(1);
echo "   User: {$user['name']}\n";

echo "   Second call (cached):\n";
$user = getUser(1);
echo "   User: {$user['name']}\n\n";

// Example 5: Tag-based Invalidation
echo "5. Tag-based Cache Invalidation:\n";
Cache::tags(['users', 'premium'])->set('user:premium:1', 'Premium User 1', 3600);
Cache::tags(['users', 'premium'])->set('user:premium:2', 'Premium User 2', 3600);
Cache::tags(['users', 'free'])->set('user:free:1', 'Free User 1', 3600);
Cache::tags(['products'])->set('product:1', 'Product 1', 3600);

echo "   Before clearing premium users:\n";
echo "   Premium User 1: " . Cache::tags(['users', 'premium'])->get('user:premium:1') . "\n";

Cache::tags(['premium'])->clear();

echo "   After clearing premium tag:\n";
echo "   Premium User 1: " . (Cache::tags(['users', 'premium'])->get('user:premium:1') ?? 'null') . "\n";
echo "   Free User 1: " . Cache::tags(['users', 'free'])->get('user:free:1') . "\n";
echo "   Product 1: " . Cache::tags(['products'])->get('product:1') . "\n\n";

// Example 6: Leaderboard with Sorted Sets (using raw Redis)
echo "6. Leaderboard Example:\n";
$redis = Cache::store('redis')->getRedis();
$leaderboardKey = Cache::getPrefix() . 'leaderboard:game1';

// Add scores
$redis->zAdd($leaderboardKey, 100, 'player1');
$redis->zAdd($leaderboardKey, 250, 'player2');
$redis->zAdd($leaderboardKey, 175, 'player3');
$redis->zAdd($leaderboardKey, 300, 'player4');

// Get top 3 players
$topPlayers = $redis->zRevRange($leaderboardKey, 0, 2, true);
echo "   Top 3 Players:\n";
foreach ($topPlayers as $player => $score) {
    echo "   {$player}: {$score} points\n";
}
echo "\n";

// Example 7: Pub/Sub Pattern
echo "7. Cache Invalidation via Pub/Sub:\n";
function publishCacheInvalidation(string $key): void {
    $redis = Cache::store('redis')->getRedis();
    $redis->publish('cache:invalidate', $key);
    echo "   Published invalidation for: {$key}\n";
}

Cache::set('config:feature_flag', true);
publishCacheInvalidation('config:feature_flag');
echo "\n";

// Example 8: Batch Operations
echo "8. Batch Operations (Pipeline):\n";
$userIds = [101, 102, 103, 104, 105];

// Store multiple users
$users = [];
foreach ($userIds as $id) {
    $users["user:{$id}"] = [
        'id' => $id,
        'name' => "User {$id}",
        'created' => time(),
    ];
}
Cache::setMultiple($users, 3600);
echo "   Stored " . count($users) . " users\n";

// Retrieve multiple users
$cached = Cache::getMultiple(array_keys($users));
echo "   Retrieved " . count($cached) . " users\n\n";

// Example 9: Distributed Locks
echo "9. Distributed Lock Pattern:\n";
function acquireLock(string $resource, int $timeout = 10): bool {
    $lockKey = "lock:{$resource}";
    $lockValue = uniqid('', true);
    
    return Cache::add($lockKey, $lockValue, $timeout);
}

function releaseLock(string $resource): void {
    Cache::delete("lock:{$resource}");
}

$resource = 'critical_section';
if (acquireLock($resource, 5)) {
    echo "   Lock acquired for {$resource}\n";
    echo "   Performing critical operation...\n";
    sleep(1);
    releaseLock($resource);
    echo "   Lock released\n";
} else {
    echo "   Failed to acquire lock\n";
}
echo "\n";

// Example 10: Cache Warming
echo "10. Cache Warming:\n";
function warmCache(): void {
    $data = [
        'config:app_name' => 'MonkeysLegion',
        'config:version' => '1.0.0',
        'config:maintenance' => false,
        'stats:total_users' => 1000,
        'stats:total_posts' => 5000,
    ];
    
    Cache::setMultiple($data, 86400); // 24 hours
    echo "   Cache warmed with " . count($data) . " items\n";
}

warmCache();
echo "   App Name: " . Cache::get('config:app_name') . "\n";
echo "   Total Users: " . Cache::get('stats:total_users') . "\n\n";

echo "=== All Redis Examples Complete ===\n";

// Cleanup
Cache::clear();
echo "\nCache cleared.\n";
