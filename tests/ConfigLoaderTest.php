<?php

namespace MonkeysLegion\Cache\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Cache\Config\ConfigLoader;

class ConfigLoaderTest extends TestCase
{
    private string $testConfigPath;
    private ConfigLoader $loader;

    protected function setUp(): void
    {
        $this->testConfigPath = sys_get_temp_dir() . '/ml_config_test_' . uniqid();
        mkdir($this->testConfigPath);
        
        $this->loader = new ConfigLoader();
    }

    protected function tearDown(): void
    {
        $this->recursiveRemove($this->testConfigPath);
    }

    public function testLoadPhpConfig(): void
    {
        $phpConfig = <<<'PHP'
<?php
return [
    'default' => 'file',
    'prefix' => 'test_',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => '/tmp/cache',
        ],
    ],
];
PHP;

        file_put_contents($this->testConfigPath . '/cache.php', $phpConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('file', $config['default']);
        $this->assertEquals('test_', $config['prefix']);
        $this->assertArrayHasKey('stores', $config);
    }

    public function testLoadMlcConfig(): void
    {
        $mlcConfig = <<<'MLC'
cache.default = "file"
cache.prefix = "test_"
cache.stores.file.driver = "file"
cache.stores.file.path = "/tmp/cache"
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('file', $config['default']);
        $this->assertEquals('test_', $config['prefix']);
        $this->assertArrayHasKey('stores', $config);
    }

    public function testMlcEnvVariables(): void
    {
        $_ENV['TEST_CACHE_DRIVER'] = 'redis';
        $_ENV['TEST_CACHE_PREFIX'] = 'env_prefix_';
        
        $mlcConfig = <<<'MLC'
cache.default = env("TEST_CACHE_DRIVER", "file")
cache.prefix = env("TEST_CACHE_PREFIX", "default_")
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('redis', $config['default']);
        $this->assertEquals('env_prefix_', $config['prefix']);
        
        unset($_ENV['TEST_CACHE_DRIVER'], $_ENV['TEST_CACHE_PREFIX']);
    }

    public function testMlcEnvDefaults(): void
    {
        $mlcConfig = <<<'MLC'
cache.default = env("NONEXISTENT_VAR", "file")
cache.prefix = env("ANOTHER_NONEXISTENT", "default_")
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('file', $config['default']);
        $this->assertEquals('default_', $config['prefix']);
    }

    public function testMlcTypeConversion(): void
    {
        $mlcConfig = <<<'MLC'
cache.stores.redis.port = 6379
cache.stores.redis.timeout = 2.5
cache.tags.enabled = true
cache.stores.redis.password = null
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertSame(6379, $config['stores']['redis']['port']);
        $this->assertSame(2.5, $config['stores']['redis']['timeout']);
        $this->assertTrue($config['tags']['enabled']);
        $this->assertNull($config['stores']['redis']['password']);
    }

    public function testMlcNestedKeys(): void
    {
        $mlcConfig = <<<'MLC'
cache.stores.redis.driver = "redis"
cache.stores.redis.host = "127.0.0.1"
cache.stores.redis.port = 6379
cache.stores.file.driver = "file"
cache.stores.file.path = "/tmp/cache"
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertArrayHasKey('redis', $config['stores']);
        $this->assertArrayHasKey('file', $config['stores']);
        $this->assertEquals('redis', $config['stores']['redis']['driver']);
        $this->assertEquals('file', $config['stores']['file']['driver']);
    }

    public function testMlcComments(): void
    {
        $mlcConfig = <<<'MLC'
# This is a comment
cache.default = "file"

# Another comment
cache.prefix = "test_"

cache.stores.file.driver = "file" # Inline comment ignored for now
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('file', $config['default']);
        $this->assertEquals('test_', $config['prefix']);
    }

    public function testMlcQuotedStrings(): void
    {
        $mlcConfig = <<<'MLC'
cache.prefix = "quoted_prefix_"
cache.stores.file.path = '/tmp/cache'
cache.stores.redis.host = "127.0.0.1"
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('quoted_prefix_', $config['prefix']);
        $this->assertEquals('/tmp/cache', $config['stores']['file']['path']);
        $this->assertEquals('127.0.0.1', $config['stores']['redis']['host']);
    }

    public function testNoConfigFileThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->loader->load($this->testConfigPath);
    }

    public function testMlcPrefersOverPhp(): void
    {
        $phpConfig = <<<'PHP'
<?php
return [
    'default' => 'php_driver',
];
PHP;

        $mlcConfig = <<<'MLC'
cache.default = "mlc_driver"
MLC;

        file_put_contents($this->testConfigPath . '/cache.php', $phpConfig);
        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('mlc_driver', $config['default']);
    }

    public function testMlcBooleanValues(): void
    {
        $mlcConfig = <<<'MLC'
cache.features.remember = true
cache.features.disabled = false
MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertTrue($config['features']['remember']);
        $this->assertFalse($config['features']['disabled']);
    }

    public function testMlcEmptyLines(): void
    {
        $mlcConfig = <<<'MLC'
cache.default = "file"


cache.prefix = "test_"


MLC;

        file_put_contents($this->testConfigPath . '/cache.mlc', $mlcConfig);
        
        $config = $this->loader->load($this->testConfigPath);
        
        $this->assertEquals('file', $config['default']);
        $this->assertEquals('test_', $config['prefix']);
    }

    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \FilesystemIterator($dir);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->recursiveRemove($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        
        @rmdir($dir);
    }
}
