# MonkeysLegion Cache v2

> High-performance, attribute-driven cache package for PHP 8.4+ with multiple drivers, atomic locks, encrypted serialization, tiered caching, and stampede protection.

[![PHP](https://img.shields.io/badge/php-%5E8.4-8892BF.svg)](https://php.net)
[![PSR-16](https://img.shields.io/badge/PSR--16-compliant-brightgreen.svg)](https://www.php-fig.org/psr/psr-16/)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Installation

```bash
composer require monkeyscloud/monkeyslegion-cache:^2.1
```

## Features

| Feature | Description |
|---|---|
| **6 Drivers** | Array, File, Redis, Memcached, Null, Chain (L1/L2) |
| **Pluggable Serializers** | PHP, JSON, igbinary, Encrypted (libsodium) |
| **Atomic Locks** | ArrayLock, FileLock, RedisLock |
| **Tag-Based Invalidation** | O(1) version-based tag invalidation |
| **Stampede Protection** | `flexible()` with probabilistic early expiration |
| **Typed Getters** | `integer()`, `boolean()`, `float()`, `string()`, `array()` |
| **TTL Extension** | `touch()` — extend TTL without re-reading value |
| **Cache Events** | Hit, Miss, Write, Delete — readonly event VOs |
| **PSR-16 Compliant** | Full `Psr\SimpleCache\CacheInterface` conformance |
| **PHP 8.4 Native** | Property hooks, asymmetric visibility, backed enums |

## Quick Start

```php
use MonkeysLegion\Cache\CacheManager;

$manager = new CacheManager([
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path'   => __DIR__ . '/cache',
        ],
        'memory' => [
            'driver' => 'array',
        ],
        'redis' => [
            'driver'   => 'redis',
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'prefix'   => 'myapp',
        ],
    ],
]);

$cache = $manager->store();           // Default (file)
$redis = $manager->store('redis');    // Named store

// Basic operations
$cache->set('user.1', $userData, ttl: 3600);
$user = $cache->get('user.1');
$cache->delete('user.1');
```

## Typed Getters

No more manual casting:

```php
$count = $cache->integer('page.views');     // int
$flag  = $cache->boolean('feature.active'); // bool
$rate  = $cache->float('exchange.rate');    // float
$name  = $cache->string('user.name');       // string
$ids   = $cache->array('active.users');     // array
```

## Remember & Stampede Protection

```php
// Cache-aside pattern
$users = $cache->remember('users.active', 3600, function () {
    return $db->query('SELECT * FROM users WHERE active = 1');
});

// Stale-while-revalidate (prevents cache stampede)
$data = $cache->flexible(
    key:      'api.results',
    ttl:      [300, 3600],    // [stale_window, fresh_ttl]
    callback: fn() => $api->fetchExpensiveData(),
    beta:     1.5,            // Higher = more aggressive early refresh
);
```

## Tags

O(1) tag invalidation using version-based namespacing:

```php
// Scoped writes
$cache->tags(['products', 'electronics'])->set('product.42', $laptop);
$cache->tags(['products', 'clothing'])->set('product.99', $shirt);

// Mass invalidation — O(1), no key scanning
$cache->tags(['electronics'])->flush();
```

## Atomic Locks

Prevent race conditions with distributed locks:

```php
$lock = $cache->lock('deploy', seconds: 30);

// Block-scoped (auto-release)
$result = $lock->get(function () {
    return deployApplication();
}, ttl: 30);

// Manual acquire/release
if ($lock->acquire()) {
    try {
        criticalSection();
    } finally {
        $lock->release();
    }
}

// Block with timeout
$lock->block(seconds: 10, callback: function () {
    return processOrder();
});
```

### Lock Backends

| Lock | Use Case |
|---|---|
| `ArrayLock` | Testing, single-process |
| `FileLock` | Single-server production |
| `RedisLock` | Multi-server distributed (Lua-based atomic release) |

## Chain Store (L1/L2 Tiered Caching)

```php
$manager = new CacheManager([
    'default' => 'tiered',
    'stores' => [
        'l1'     => ['driver' => 'array'],      // Fast, in-process
        'l2'     => ['driver' => 'redis', ...],  // Shared, durable
        'tiered' => ['driver' => 'chain', 'stores' => ['l1', 'l2']],
    ],
]);

$cache = $manager->store(); // ChainStore
$cache->get('key'); // L1 miss → L2 hit → promoted to L1
```

## Encrypted Serialization

Transparent at-rest encryption for GDPR/PCI compliance:

```php
$manager = new CacheManager([
    'encrypt_key' => env('CACHE_ENCRYPT_KEY'), // 32+ bytes
    'stores' => [
        'secure' => [
            'driver'    => 'redis',
            'encrypt'   => true,     // Wrap with sodium_crypto_secretbox
            'serializer' => 'json',  // Encrypt JSON payloads
        ],
    ],
]);
```

## TTL Extension (touch)

Extend cache TTL without re-reading the value (single round-trip on Redis):

```php
// Extend session expiry
$cache->touch('session.abc123', ttl: 1800);
```

## Cache Events

Readonly event value objects for telemetry/debugging:

```php
use MonkeysLegion\Cache\Event\CacheEvent;
use MonkeysLegion\Cache\Event\CacheEventType;

$event = new CacheEvent(
    type:     CacheEventType::Hit,
    key:      'user.1',
    store:    'redis',
    duration: 0.35,
);

echo $event->summary; // [redis] hit: user.1 (0.35μs)
```

## CacheEntry Value Object

Introspect cached data with computed properties:

```php
use MonkeysLegion\Cache\CacheEntry;

$entry = new CacheEntry(
    value:     $data,
    expiresAt: time() + 3600,
    tags:      ['user', 'premium'],
);

$entry->isExpired;     // false (property hook)
$entry->remainingTtl;  // ~3600 (property hook)
$entry->age;           // 0 (property hook)
$entry->shouldRefresh(beta: 1.5); // probabilistic early refresh
```

## Cache Stats

```php
$stats = $cache->getStats();

echo $stats->hits;            // 1423
echo $stats->hitRate;         // 0.95 (property hook)
echo $stats->memoryFormatted; // "2.45 MB" (property hook)
```

## Pluggable Serializers

| Serializer | Best For |
|---|---|
| `PhpSerializer` | Default, supports all PHP types |
| `JsonSerializer` | Scalars/arrays, zero code execution risk |
| `IgbinarySerializer` | 30-50% smaller payloads (requires ext-igbinary) |
| `EncryptedSerializer` | Decorator, transparent at-rest encryption |

```php
'stores' => [
    'json-store' => [
        'driver'     => 'redis',
        'serializer' => 'json', // or 'php', 'igbinary'
    ],
],
```

## Extending with Custom Drivers

```php
$manager->extend('dynamodb', function (array $config) {
    return new DynamoDbStore($config);
});
```

## PHP 8.4 Features Used

- **Property hooks** — `CacheEntry`, `CacheStats`, `CacheEvent`, `TaggedCache`, `ChainStore`
- **Asymmetric visibility** — `CacheEntry`, `CacheLock`, `CacheEvent`
- **Backed enums** — `SerializerType`, `CacheEventType`, `LockState`
- **`new` in initializers** — Default `PhpSerializer()` in constructors
- **`match` expressions** — Driver/serializer resolution
- **`declare(strict_types=1)`** — Every file

## Testing

```bash
php vendor/bin/phpunit --testdox
```

**98 tests, 190 assertions** — covers all stores, serializers, locks, tags, events, enums, and CacheManager.

## License

MIT © [MonkeysCloud](https://monkeys.cloud)
