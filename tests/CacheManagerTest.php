<?php

namespace MonkeysLegion\Cache\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\Cache;

class CacheManagerTest extends TestCase
{
    private CacheManager $manager;
    private string $testCachePath;

    protected function setUp(): void
    {
        $this->testCachePath = sys_get_temp_dir() . '/ml_cache_test_' . uniqid();
        
        $config = [
            'default' => 'array',
            'stores' => [
                'array' => [
                    'driver' => 'array',
                    'prefix' => 'test_',
                ],
                'file' => [
                    'driver' => 'file',
                    'path' => $this->testCachePath,
                    'prefix' => 'file_test_',
                ],
            ],
        ];

        $this->manager = new CacheManager($config);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testCachePath)) {
            $this->recursiveRemove($this->testCachePath);
        }
    }

    public function testGetDefaultStore(): void
    {
        $store = $this->manager->store();
        $this->assertInstanceOf(\MonkeysLegion\Cache\CacheInterface::class, $store);
    }

    public function testGetSpecificStore(): void
    {
        $arrayStore = $this->manager->store('array');
        $fileStore = $this->manager->store('file');
        
        $this->assertInstanceOf(\MonkeysLegion\Cache\Stores\ArrayStore::class, $arrayStore);
        $this->assertInstanceOf(\MonkeysLegion\Cache\Stores\FileStore::class, $fileStore);
    }

    public function testStoreInstanceCaching(): void
    {
        $store1 = $this->manager->store('array');
        $store2 = $this->manager->store('array');
        
        $this->assertSame($store1, $store2);
    }

    public function testInvalidStore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager->store('invalid');
    }

    public function testInvalidDriver(): void
    {
        $config = [
            'default' => 'test',
            'stores' => [
                'test' => [
                    'driver' => 'nonexistent',
                ],
            ],
        ];

        $manager = new CacheManager($config);
        
        $this->expectException(\InvalidArgumentException::class);
        $manager->store('test');
    }

    public function testGetDefaultDriver(): void
    {
        $this->assertEquals('array', $this->manager->getDefaultDriver());
    }

    public function testSetDefaultDriver(): void
    {
        $this->manager->setDefaultDriver('file');
        $this->assertEquals('file', $this->manager->getDefaultDriver());
    }

    public function testMagicCall(): void
    {
        $this->manager->set('key', 'value');
        $this->assertEquals('value', $this->manager->get('key'));
        
        $this->assertTrue($this->manager->has('key'));
        $this->manager->delete('key');
        $this->assertFalse($this->manager->has('key'));
    }

    public function testMultipleStoresIsolation(): void
    {
        $arrayStore = $this->manager->store('array');
        $fileStore = $this->manager->store('file');
        
        $arrayStore->set('key', 'array_value');
        $fileStore->set('key', 'file_value');
        
        $this->assertEquals('array_value', $arrayStore->get('key'));
        $this->assertEquals('file_value', $fileStore->get('key'));
    }

    public function testFacadeIntegration(): void
    {
        Cache::setInstance($this->manager);
        
        Cache::set('facade_key', 'facade_value');
        $this->assertEquals('facade_value', Cache::get('facade_key'));
        
        Cache::store('file')->set('file_key', 'file_value');
        $this->assertEquals('file_value', Cache::store('file')->get('file_key'));
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
