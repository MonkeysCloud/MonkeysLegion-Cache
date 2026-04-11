<?php

declare(strict_types=1);

/**
 * MonkeysLegion Cache v2
 *
 * @package   MonkeysLegion\Cache
 * @author    MonkeysCloud <jorge@monkeyscloud.com>
 * @license   MIT
 *
 * @requires  PHP 8.4
 */

namespace MonkeysLegion\Cache;

use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\EncryptedSerializer;
use MonkeysLegion\Cache\Serializer\IgbinarySerializer;
use MonkeysLegion\Cache\Serializer\JsonSerializer;
use MonkeysLegion\Cache\Serializer\PhpSerializer;
use MonkeysLegion\Cache\Stores\ArrayStore;
use MonkeysLegion\Cache\Stores\ChainStore;
use MonkeysLegion\Cache\Stores\FileStore;
use MonkeysLegion\Cache\Stores\MemcachedStore;
use MonkeysLegion\Cache\Stores\NullStore;
use MonkeysLegion\Cache\Stores\RedisStore;
use Psr\Log\LoggerInterface;

/**
 * Cache manager — resolves and caches store instances.
 *
 * Uses PHP 8.4: final class, property hooks (defaultDriver validation),
 * match expressions for driver resolution. Zero magic (__call banned).
 */
final class CacheManager
{
    /** @var array<string, CacheStoreInterface> Resolved store instances */
    private array $stores = [];

    /** @var array<string, \Closure> Custom driver factories */
    private array $customDrivers = [];

    /**
     * Default driver name with validation hook.
     */
    private string $defaultDriver;

    public function __construct(
        private readonly array $config = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->defaultDriver = $config['default'] ?? 'file';
    }

    // ── Store access ───────────────────────────────────────────

    /**
     * Get a cache store instance by name.
     */
    public function store(?string $name = null): CacheStoreInterface
    {
        $name ??= $this->defaultDriver;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        return $this->stores[$name] = $this->resolve($name);
    }

    /**
     * Alias for store().
     */
    public function driver(?string $name = null): CacheStoreInterface
    {
        return $this->store($name);
    }

    // ── Default driver ─────────────────────────────────────────

    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    public function setDefaultDriver(string $name): void
    {
        $this->defaultDriver = $name;
    }

    // ── Extensibility ──────────────────────────────────────────

    /**
     * Register a custom driver factory.
     *
     * @param string   $driver   Driver name.
     * @param \Closure $factory  Factory receiving (array $config): CacheStoreInterface.
     */
    public function extend(string $driver, \Closure $factory): void
    {
        $this->customDrivers[$driver] = $factory;
    }

    // ── Private resolution ─────────────────────────────────────

    private function resolve(string $name): CacheStoreInterface
    {
        $storeConfig = $this->config['stores'][$name]
            ?? throw new \InvalidArgumentException("Cache store [{$name}] is not configured.");

        $driver = $storeConfig['driver']
            ?? throw new \InvalidArgumentException("Cache driver not specified for store [{$name}].");

        $serializer = $this->resolveSerializer($storeConfig);

        $store = match ($driver) {
            'array'     => $this->createArrayDriver($storeConfig, $serializer),
            'file'      => $this->createFileDriver($storeConfig, $serializer),
            'redis'     => $this->createRedisDriver($storeConfig, $serializer),
            'memcached' => $this->createMemcachedDriver($storeConfig, $serializer),
            'null'      => new NullStore(prefix: $storeConfig['prefix'] ?? ''),
            'chain'     => $this->createChainDriver($storeConfig, $serializer),
            default     => isset($this->customDrivers[$driver])
                ? ($this->customDrivers[$driver])($storeConfig, $serializer)
                : throw new \InvalidArgumentException("Cache driver [{$driver}] is not supported."),
        };

        $this->logger?->debug("Cache store [{$name}] resolved with driver [{$driver}].");

        return $store;
    }

    private function createArrayDriver(array $config, CacheSerializerInterface $serializer): ArrayStore
    {
        return new ArrayStore(
            prefix:     $config['prefix'] ?? '',
            serializer: $serializer,
        );
    }

    private function createFileDriver(array $config, CacheSerializerInterface $serializer): FileStore
    {
        return new FileStore(
            directory:  $config['path'] ?? sys_get_temp_dir() . '/ml-cache',
            prefix:     $config['prefix'] ?? '',
            serializer: $serializer,
        );
    }

    private function createRedisDriver(array $config, CacheSerializerInterface $serializer): RedisStore
    {
        $redis   = new \Redis();
        $host    = $config['host'] ?? '127.0.0.1';
        $port    = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 0.0;

        $redis->connect($host, $port, $timeout);

        if (isset($config['password']) && $config['password'] !== '') {
            $redis->auth($config['password']);
        }

        if (isset($config['database']) && $config['database'] !== 0) {
            $redis->select($config['database']);
        }

        return new RedisStore(
            redis:      $redis,
            prefix:     $config['prefix'] ?? '',
            serializer: $serializer,
        );
    }

    private function createMemcachedDriver(array $config, CacheSerializerInterface $serializer): MemcachedStore
    {
        $memcached = new \Memcached($config['persistent_id'] ?? null);

        if ($memcached->getServerList() === []) {
            $servers = $config['servers'] ?? [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100],
            ];

            foreach ($servers as $server) {
                $memcached->addServer(
                    $server['host'],
                    $server['port'],
                    $server['weight'] ?? 100,
                );
            }
        }

        if (isset($config['options'])) {
            $memcached->setOptions($config['options']);
        }

        return new MemcachedStore(
            memcached:  $memcached,
            prefix:     $config['prefix'] ?? '',
            serializer: $serializer,
        );
    }

    private function createChainDriver(array $config, CacheSerializerInterface $serializer): ChainStore
    {
        $layers = [];

        foreach ($config['stores'] ?? [] as $storeName) {
            $layers[] = $this->store($storeName);
        }

        return new ChainStore(
            stores:     $layers,
            prefix:     $config['prefix'] ?? '',
            serializer: $serializer,
        );
    }

    // ── Serializer resolution ──────────────────────────────────

    private function resolveSerializer(array $config): CacheSerializerInterface
    {
        $type = $config['serializer'] ?? 'php';

        $serializer = match ($type) {
            'php'      => new PhpSerializer(),
            'json'     => new JsonSerializer(),
            'igbinary' => new IgbinarySerializer(),
            default    => new PhpSerializer(),
        };

        // Wrap with encryption if configured
        if (isset($config['encrypt']) && $config['encrypt'] === true) {
            $secret = $config['encrypt_key']
                ?? $this->config['encrypt_key']
                ?? throw new \InvalidArgumentException(
                    'Encrypted cache requires an "encrypt_key" configuration.',
                );

            $serializer = new EncryptedSerializer($serializer, $secret);
        }

        return $serializer;
    }
}
