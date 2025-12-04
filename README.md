# MonkeysLegion Cache

A comprehensive caching package for the MonkeysLegion Framework with support for multiple drivers and PSR-16 SimpleCache compliance.

## Features

- **Multiple Cache Drivers**: File, Redis, Memcached, Array (in-memory)
- **PSR-16 Compliant**: Implements the Simple Cache interface
- **Cache Tagging**: Group cache entries and flush them together
- **Atomic Operations**: Increment/decrement numeric values
- **Remember Pattern**: Get or compute and cache values
- **CLI Commands**: Manage cache via command line
- **Helper Functions**: Convenient global functions for cache operations

## Installation

```bash
composer require monkeyscloud/monkeyslegion-cache
```

## Configuration

Create a `cache.php` configuration file:

```php
return [
    'default' => 'file',
    
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => __DIR__ . '/../storage/cache',
            'prefix' => 'ml_cache',
        ],
        
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'password' => null,
            'port' => 6379,
            'database' => 1,
            'prefix' => 'ml_cache',
        ],
        
        'memcached' => [
            'driver' => 'memcached',
            'prefix' => 'ml_cache',
            'servers' => [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100],
            ],
        ],
        
        'array' => [
            'driver' => 'array',
            'prefix' => 'ml_cache',
        ],
    ],
];
```

### MLC Configuration Format (Optional)

The package also supports [MonkeysLegion-Mlc](https://github.com/MonkeysCloud/MonkeysLegion-Mlc) `.mlc` format:

```mlc
# config/cache.mlc
cache.default = env("CACHE_DRIVER", "file")
cache.prefix = env("CACHE_PREFIX", "ml_cache")

cache.stores.redis.driver = "redis"
cache.stores.redis.host = env("REDIS_HOST", "127.0.0.1")
cache.stores.redis.port = env("REDIS_PORT", 6379)
```

**Benefits:**
- ✅ Clean dot-notation syntax
- ✅ Environment variable support with defaults
- ✅ Layered .env files
- ✅ Type-aware values

See [MLC-CONFIG.md](MLC-CONFIG.md) for complete documentation.

## Basic Usage

### Initializing the Cache Manager

```php
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\Cache;

$config = require 'cache.php';
$manager = new CacheManager($config);

// Set the facade instance
Cache::setInstance($manager);
```

### Storing Items

```php
// Store for a specific time (in seconds)
Cache::set('key', 'value', 3600);

// Store forever
Cache::forever('key', 'value');

// Store if key doesn't exist
Cache::add('key', 'value', 3600);

// Store multiple items
Cache::putMany([
    'key1' => 'value1',
    'key2' => 'value2',
], 3600);
```

### Retrieving Items

```php
// Get an item
$value = Cache::get('key');

// Get with default value
$value = Cache::get('key', 'default');

// Get multiple items
$values = Cache::getMultiple(['key1', 'key2'], 'default');

// Get and delete
$value = Cache::pull('key');
```

### Remember Pattern

```php
// Get from cache or execute callback and store result
$users = Cache::remember('users', 3600, function() {
    return User::all();
});

// Remember forever
$settings = Cache::rememberForever('settings', function() {
    return Settings::all();
});
```

### Checking Existence

```php
if (Cache::has('key')) {
    // Key exists
}
```

### Deleting Items

```php
// Delete single item
Cache::delete('key');

// Delete multiple items
Cache::deleteMultiple(['key1', 'key2']);

// Clear all cache
Cache::clear();
```

### Incrementing/Decrementing

```php
// Increment
Cache::increment('counter');
Cache::increment('counter', 5);

// Decrement
Cache::decrement('counter');
Cache::decrement('counter', 5);
```

### Cache Tagging

```php
// Store with tags
Cache::tags(['users', 'premium'])->set('user:1', $user, 3600);

// Retrieve tagged items
$user = Cache::tags(['users', 'premium'])->get('user:1');

// Flush all items with specific tags
Cache::tags(['users'])->clear();
Cache::tags(['users', 'premium'])->clear();
```

## Using Different Stores

```php
// Use specific store
Cache::store('redis')->set('key', 'value');

// Chain methods
Cache::store('redis')->tags(['api'])->set('key', 'value', 3600);
```

## Helper Functions

```php
// Get/Set cache
cache('key'); // Get
cache('key', 'value'); // Set
cache(['key1' => 'value1', 'key2' => 'value2']); // Set multiple

// Remember pattern
cache_remember('key', 3600, fn() => expensiveOperation());
cache_forever('key', fn() => expensiveOperation());

// Other operations
cache_forget('key');
cache_flush();
cache_has('key');
cache_pull('key');
cache_add('key', 'value', 3600);
```

## CLI Commands

### Clear Cache

```bash
# Clear default store
php ml cache:clear

# Clear specific store
php ml cache:clear --store=redis

# Clear by tags
php ml cache:clear --tags=users,posts
```

### Get Value

```bash
# Get value
php ml cache:get user:123

# Get from specific store
php ml cache:get user:123 --store=redis

# Get as JSON
php ml cache:get user:123 --format=json
```

### Set Value

```bash
# Set value
php ml cache:set user:123 "John Doe"

# Set with TTL (seconds)
php ml cache:set config:debug true --ttl=3600

# Set in specific store
php ml cache:set user:data '{"name":"John"}' --store=redis
```

### Forget Key

```bash
# Delete key
php ml cache:forget user:123

# Delete multiple keys
php ml cache:forget user:123,user:456

# Delete from specific store
php ml cache:forget user:123 --store=redis
```

### Cache Statistics

```bash
# Show stats for default store
php ml cache:stats

# Show stats for specific store
php ml cache:stats --store=redis
```

## Cache Drivers

### File Driver

Stores cache in the filesystem with automatic directory structure and expiration handling.

```php
'file' => [
    'driver' => 'file',
    'path' => '/path/to/cache',
    'prefix' => 'ml_cache',
],
```

### Redis Driver

Uses Redis for high-performance caching.

```php
'redis' => [
    'driver' => 'redis',
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => null,
    'database' => 1,
    'prefix' => 'ml_cache',
],
```

### Memcached Driver

Uses Memcached for distributed caching.

```php
'memcached' => [
    'driver' => 'memcached',
    'persistent_id' => 'my_app',
    'servers' => [
        ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100],
    ],
    'prefix' => 'ml_cache',
],
```

### Array Driver

In-memory cache for testing or single-request scenarios.

```php
'array' => [
    'driver' => 'array',
    'prefix' => 'ml_cache',
],
```

## Advanced Usage

### Direct Store Access

```php
use MonkeysLegion\Cache\Stores\RedisStore;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$store = new RedisStore($redis, 'prefix_');
$store->set('key', 'value', 3600);
```

### Custom Cache Key Prefix

```php
Cache::store('redis')->getPrefix(); // Get prefix
```

### Working with Raw Connections

```php
// Redis
$redis = Cache::store('redis')->getRedis();

// Memcached
$memcached = Cache::store('memcached')->getMemcached();
```

## Testing

The ArrayStore is perfect for testing:

```php
$cache = new CacheManager([
    'default' => 'array',
    'stores' => [
        'array' => ['driver' => 'array']
    ]
]);

// Cache won't persist between requests
```

## Best Practices

1. **Use appropriate TTL**: Set expiration times based on data volatility
2. **Use tags**: Group related cache items for easier management
3. **Remember pattern**: Simplifies cache-or-compute logic
4. **Prefix keys**: Prevent collisions in shared cache systems
5. **Clear strategically**: Use tags to clear related items without flushing all

## License

MIT License
