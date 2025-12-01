<?php

namespace MonkeysLegion\Cache\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Cache\Stores\FileStore;

class FileStoreTest extends TestCase
{
    private FileStore $cache;
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/ml_file_cache_test_' . uniqid();
        $this->cache = new FileStore($this->cachePath, 'test_');
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
        
        if (is_dir($this->cachePath)) {
            $this->recursiveRemove($this->cachePath);
        }
    }

    public function testDirectoryCreation(): void
    {
        $this->assertTrue(is_dir($this->cachePath));
    }

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->cache->set('key', 'value'));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testExpiration(): void
    {
        $this->cache->set('key', 'value', 1);
        $this->assertEquals('value', $this->cache->get('key'));
        
        sleep(2);
        
        $this->assertNull($this->cache->get('key'));
    }

    public function testFilesAreCreated(): void
    {
        $this->cache->set('test_key', 'test_value');
        
        // Check that cache directory contains files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachePath, \FilesystemIterator::SKIP_DOTS)
        );
        
        $fileCount = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileCount++;
            }
        }
        
        $this->assertGreaterThan(0, $fileCount);
    }

    public function testClearRemovesFiles(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');
        
        $this->cache->clear();
        
        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
        $this->assertNull($this->cache->get('key3'));
    }

    public function testDeleteRemovesFile(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
        
        $this->assertTrue($this->cache->delete('key'));
        $this->assertFalse($this->cache->has('key'));
    }

    public function testMultipleValuesInDifferentDirectories(): void
    {
        // Set enough keys to span multiple directories
        for ($i = 0; $i < 50; $i++) {
            $this->cache->set("key_{$i}", "value_{$i}");
        }
        
        for ($i = 0; $i < 50; $i++) {
            $this->assertEquals("value_{$i}", $this->cache->get("key_{$i}"));
        }
    }

    public function testComplexDataTypes(): void
    {
        $data = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'array' => [1, 2, 3],
            'object' => (object)['name' => 'John'],
        ];
        
        $this->cache->set('complex', $data);
        $retrieved = $this->cache->get('complex');
        
        $this->assertEquals($data, $retrieved);
    }

    public function testConcurrentWrites(): void
    {
        $iterations = 100;
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->cache->set("concurrent_{$i}", "value_{$i}");
        }
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->assertEquals("value_{$i}", $this->cache->get("concurrent_{$i}"));
        }
    }

    public function testIncrement(): void
    {
        $this->cache->set('counter', 0);
        $this->assertEquals(1, $this->cache->increment('counter'));
        $this->assertEquals(2, $this->cache->increment('counter'));
        $this->assertEquals(7, $this->cache->increment('counter', 5));
    }

    public function testGetPrefix(): void
    {
        $this->assertEquals('test_', $this->cache->getPrefix());
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
