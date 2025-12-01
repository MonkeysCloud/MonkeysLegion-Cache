<?php

namespace MonkeysLegion\Cache;

use MonkeysLegion\Cache\Stores\FileStore;
use MonkeysLegion\Cache\Stores\RedisStore;
use MonkeysLegion\Cache\Stores\ArrayStore;
use MonkeysLegion\Cache\Stores\MemcachedStore;

/**
 * CacheManager
 *
 * @package MonkeysLegion\Cache
 */
class CacheManager
{
    /**
     * The cache store instances
     *
     * @var array
     */
    private array $stores = [];

    /**
     * The cache configuration
     *
     * @var array
     */
    private array $config = [];

    /**
     * The default cache driver
     *
     * @var string
     */
    private string $defaultDriver;

    /**
     * Create a new CacheManager instance
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultDriver = $config['default'] ?? 'file';
    }

    /**
     * Get a cache store instance
     *
     * @param string|null $name
     * @return CacheInterface
     */
    public function store(?string $name = null): CacheInterface
    {
        $name = $name ?? $this->defaultDriver;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        return $this->stores[$name] = $this->resolve($name);
    }

    /**
     * Resolve a cache store instance
     *
     * @param string $name
     * @return CacheInterface
     */
    protected function resolve(string $name): CacheInterface
    {
        $config = $this->config['stores'][$name] ?? throw new \InvalidArgumentException("Cache store [{$name}] is not defined.");

        $driver = $config['driver'] ?? throw new \InvalidArgumentException("Cache driver not specified for store [{$name}].");
        
        $method = 'create' . ucfirst($driver) . 'Driver';

        if (!method_exists($this, $method)) {
            throw new \InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        return $this->$method($config);
    }

    /**
     * Create a file cache driver
     *
     * @param array $config
     * @return FileStore
     */
    protected function createFileDriver(array $config): FileStore
    {
        return new FileStore(
            $config['path'] ?? sys_get_temp_dir() . '/cache',
            $config['prefix'] ?? ''
        );
    }

    /**
     * Create a Redis cache driver
     *
     * @param array $config
     * @return RedisStore
     */
    protected function createRedisDriver(array $config): RedisStore
    {
        $redis = new \Redis();
        
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $timeout = $config['timeout'] ?? 0.0;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;

        $redis->connect($host, $port, $timeout);

        if ($password) {
            $redis->auth($password);
        }

        if ($database) {
            $redis->select($database);
        }

        return new RedisStore($redis, $config['prefix'] ?? '');
    }

    /**
     * Create an array cache driver
     *
     * @param array $config
     * @return ArrayStore
     */
    protected function createArrayDriver(array $config): ArrayStore
    {
        return new ArrayStore($config['prefix'] ?? '');
    }

    /**
     * Create a Memcached cache driver
     *
     * @param array $config
     * @return MemcachedStore
     */
    protected function createMemcachedDriver(array $config): MemcachedStore
    {
        $memcached = new \Memcached($config['persistent_id'] ?? null);

        if (!count($memcached->getServerList())) {
            $servers = $config['servers'] ?? [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100]
            ];

            foreach ($servers as $server) {
                $memcached->addServer(
                    $server['host'],
                    $server['port'],
                    $server['weight'] ?? 100
                );
            }
        }

        // Set options
        if (isset($config['options'])) {
            $memcached->setOptions($config['options']);
        }

        return new MemcachedStore($memcached, $config['prefix'] ?? '');
    }

    /**
     * Get the default cache driver name
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * Set the default cache driver name
     *
     * @param string $name
     */
    public function setDefaultDriver(string $name): void
    {
        $this->defaultDriver = $name;
    }

    /**
     * Dynamically call the default driver instance
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->$method(...$parameters);
    }
}
