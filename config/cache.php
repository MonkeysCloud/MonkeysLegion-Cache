<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library.
    |
    | Supported: "file", "redis", "memcached", "array"
    |
    */
    'default' => env('CACHE_DRIVER', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    */
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => env('CACHE_PATH', __DIR__ . '/../storage/cache'),
            'prefix' => env('CACHE_PREFIX', 'ml_cache'),
        ],

        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_CACHE_DB', 1),
            'prefix' => env('CACHE_PREFIX', 'ml_cache'),
            'timeout' => 0.0,
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'prefix' => env('CACHE_PREFIX', 'ml_cache'),
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
        ],

        'array' => [
            'driver' => 'array',
            'prefix' => env('CACHE_PREFIX', 'ml_cache'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing a RAM based store such as Redis or Memcached, there might
    | be other applications utilizing the same cache. So, we'll specify a
    | prefix to prevent collisions.
    |
    */
    'prefix' => env('CACHE_PREFIX', 'ml_cache'),
];
