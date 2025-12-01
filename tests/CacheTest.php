<?php

namespace MonkeysLegion\Cache\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Cache\Stores\ArrayStore;

class CacheTest extends TestCase
{
    private ArrayStore $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayStore('test_');
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
    }

    // ========================================
    // Basic PSR-16 Tests
    // ========================================

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->cache->set('key', 'value'));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->cache->get('missing', 'default'));
        $this->assertNull($this->cache->get('missing'));
    }

    public function testHas(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
        $this->assertFalse($this->cache->has('missing'));
    }

    public function testDelete(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->delete('key'));
        $this->assertFalse($this->cache->has('key'));
        
        // Deleting non-existent key should return true
        $this->assertTrue($this->cache->delete('non_existent'));
    }

    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->assertTrue($this->cache->clear());
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function testGetMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $values = $this->cache->getMultiple(['key1', 'key2', 'key3'], 'default');
        
        $this->assertEquals('value1', $values['key1']);
        $this->assertEquals('value2', $values['key2']);
        $this->assertEquals('default', $values['key3']);
    }

    public function testSetMultiple(): void
    {
        $values = ['key1' => 'value1', 'key2' => 'value2'];
        $this->assertTrue($this->cache->setMultiple($values));
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');
        
        $this->assertTrue($this->cache->deleteMultiple(['key1', 'key2']));
        
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertTrue($this->cache->has('key3'));
    }

    // ========================================
    // TTL and Expiration Tests
    // ========================================

    public function testExpiration(): void
    {
        $this->cache->set('key', 'value', 1);
        $this->assertEquals('value', $this->cache->get('key'));
        
        sleep(2);
        
        $this->assertNull($this->cache->get('key'));
        $this->assertFalse($this->cache->has('key'));
    }

    public function testExpirationWithDateInterval(): void
    {
        $interval = new \DateInterval('PT1S'); // 1 second
        $this->cache->set('key', 'value', $interval);
        $this->assertEquals('value', $this->cache->get('key'));
        
        sleep(2);
        
        $this->assertNull($this->cache->get('key'));
    }

    public function testNoExpiration(): void
    {
        $this->cache->set('key', 'value', null);
        sleep(1);
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testForever(): void
    {
        $this->assertTrue($this->cache->forever('key', 'value'));
        sleep(1);
        $this->assertEquals('value', $this->cache->get('key'));
    }

    // ========================================
    // Extended Functionality Tests
    // ========================================

    public function testRemember(): void
    {
        $callCount = 0;
        
        $value = $this->cache->remember('key', 3600, function() use (&$callCount) {
            $callCount++;
            return 'computed';
        });
        
        $this->assertEquals('computed', $value);
        $this->assertEquals(1, $callCount);
        
        // Second call should use cached value
        $value = $this->cache->remember('key', 3600, function() use (&$callCount) {
            $callCount++;
            return 'computed';
        });
        
        $this->assertEquals('computed', $value);
        $this->assertEquals(1, $callCount); // Callback not called again
    }

    public function testRememberForever(): void
    {
        $callCount = 0;
        
        $value = $this->cache->rememberForever('key', function() use (&$callCount) {
            $callCount++;
            return 'forever';
        });
        
        $this->assertEquals('forever', $value);
        $this->assertEquals(1, $callCount);
        
        // Second call should use cached value
        $value = $this->cache->rememberForever('key', function() use (&$callCount) {
            $callCount++;
            return 'forever';
        });
        
        $this->assertEquals('forever', $value);
        $this->assertEquals(1, $callCount);
    }

    public function testIncrement(): void
    {
        $this->assertEquals(1, $this->cache->increment('counter'));
        $this->assertEquals(2, $this->cache->increment('counter'));
        $this->assertEquals(7, $this->cache->increment('counter', 5));
        $this->assertEquals(7, $this->cache->get('counter'));
    }

    public function testIncrementNonExistentKey(): void
    {
        $this->assertEquals(1, $this->cache->increment('new_counter'));
    }

    public function testDecrement(): void
    {
        $this->cache->set('counter', 10);
        $this->assertEquals(9, $this->cache->decrement('counter'));
        $this->assertEquals(4, $this->cache->decrement('counter', 5));
        $this->assertEquals(4, $this->cache->get('counter'));
    }

    public function testDecrementNonExistentKey(): void
    {
        $this->assertEquals(-1, $this->cache->decrement('new_counter'));
    }

    public function testPull(): void
    {
        $this->cache->set('key', 'value');
        $value = $this->cache->pull('key');
        
        $this->assertEquals('value', $value);
        $this->assertFalse($this->cache->has('key'));
    }

    public function testPullWithDefault(): void
    {
        $value = $this->cache->pull('missing', 'default');
        $this->assertEquals('default', $value);
    }

    public function testAdd(): void
    {
        $this->assertTrue($this->cache->add('key', 'value'));
        $this->assertFalse($this->cache->add('key', 'new value'));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testPutMany(): void
    {
        $this->assertTrue($this->cache->putMany([
            'key1' => 'value1',
            'key2' => 'value2',
        ]));
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
    }

    // ========================================
    // Data Type Tests
    // ========================================

    public function testStoreString(): void
    {
        $this->cache->set('string', 'hello world');
        $this->assertEquals('hello world', $this->cache->get('string'));
    }

    public function testStoreInteger(): void
    {
        $this->cache->set('int', 42);
        $this->assertSame(42, $this->cache->get('int'));
    }

    public function testStoreFloat(): void
    {
        $this->cache->set('float', 3.14);
        $this->assertSame(3.14, $this->cache->get('float'));
    }

    public function testStoreBoolean(): void
    {
        $this->cache->set('bool_true', true);
        $this->cache->set('bool_false', false);
        
        $this->assertTrue($this->cache->get('bool_true'));
        $this->assertFalse($this->cache->get('bool_false'));
    }

    public function testStoreNull(): void
    {
        $this->cache->set('null', null);
        // Null value should be stored, but get() returns default for missing keys
        // This is tricky - we need to check has() first
        $this->assertTrue($this->cache->has('null'));
    }

    public function testStoreArray(): void
    {
        $data = ['name' => 'John', 'age' => 30];
        $this->cache->set('array', $data);
        $this->assertEquals($data, $this->cache->get('array'));
    }

    public function testStoreObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'John';
        $obj->age = 30;
        
        $this->cache->set('object', $obj);
        $retrieved = $this->cache->get('object');
        
        $this->assertEquals($obj, $retrieved);
    }

    // ========================================
    // Cache Tags Tests
    // ========================================

    public function testTags(): void
    {
        $this->cache->tags(['users'])->set('user:1', 'John');
        $this->cache->tags(['users'])->set('user:2', 'Jane');
        $this->cache->tags(['posts'])->set('post:1', 'Post content');
        
        $this->assertEquals('John', $this->cache->tags(['users'])->get('user:1'));
        
        $this->cache->tags(['users'])->clear();
        
        $this->assertNull($this->cache->tags(['users'])->get('user:1'));
        $this->assertNull($this->cache->tags(['users'])->get('user:2'));
        $this->assertEquals('Post content', $this->cache->tags(['posts'])->get('post:1'));
    }

    public function testMultipleTags(): void
    {
        $this->cache->tags(['users', 'premium'])->set('user:1', 'Premium User');
        $this->cache->tags(['users', 'free'])->set('user:2', 'Free User');
        
        $this->assertEquals('Premium User', $this->cache->tags(['users', 'premium'])->get('user:1'));
        
        // Clear premium tag
        $this->cache->tags(['premium'])->clear();
        
        $this->assertNull($this->cache->tags(['users', 'premium'])->get('user:1'));
        $this->assertEquals('Free User', $this->cache->tags(['users', 'free'])->get('user:2'));
    }

    // ========================================
    // Edge Cases and Error Handling
    // ========================================

    public function testEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache->set('', 'value');
    }

    public function testLargeValue(): void
    {
        $largeValue = str_repeat('x', 10000);
        $this->cache->set('large', $largeValue);
        $this->assertEquals($largeValue, $this->cache->get('large'));
    }

    public function testSpecialCharactersInKey(): void
    {
        $this->cache->set('key:with:colons', 'value');
        $this->cache->set('key.with.dots', 'value');
        $this->cache->set('key_with_underscores', 'value');
        
        $this->assertEquals('value', $this->cache->get('key:with:colons'));
        $this->assertEquals('value', $this->cache->get('key.with.dots'));
        $this->assertEquals('value', $this->cache->get('key_with_underscores'));
    }

    public function testGetPrefix(): void
    {
        $this->assertEquals('test_', $this->cache->getPrefix());
    }

    public function testClearWithTags(): void
    {
        $this->cache->set('untagged', 'value');
        $this->cache->tags(['test'])->set('tagged', 'value');
        
        $this->cache->tags(['test'])->clear();
        
        $this->assertEquals('value', $this->cache->get('untagged'));
        $this->assertNull($this->cache->tags(['test'])->get('tagged'));
    }

    // ========================================
    // Performance and Stress Tests
    // ========================================

    public function testManyKeys(): void
    {
        $count = 1000;
        
        for ($i = 0; $i < $count; $i++) {
            $this->cache->set("key_{$i}", "value_{$i}");
        }
        
        for ($i = 0; $i < $count; $i++) {
            $this->assertEquals("value_{$i}", $this->cache->get("key_{$i}"));
        }
        
        $this->cache->clear();
        
        for ($i = 0; $i < $count; $i++) {
            $this->assertNull($this->cache->get("key_{$i}"));
        }
    }

    public function testConcurrentOperations(): void
    {
        $this->cache->set('counter', 0);
        
        for ($i = 0; $i < 100; $i++) {
            $this->cache->increment('counter');
        }
        
        $this->assertEquals(100, $this->cache->get('counter'));
    }

    // ========================================
    // Integration Tests
    // ========================================

    public function testComplexWorkflow(): void
    {
        // Set initial values
        $this->cache->set('user:1', ['name' => 'John', 'visits' => 0]);
        
        // Get and modify
        $user = $this->cache->get('user:1');
        $user['visits']++;
        $this->cache->set('user:1', $user);
        
        // Verify
        $this->assertEquals(1, $this->cache->get('user:1')['visits']);
        
        // Use remember pattern
        $user = $this->cache->remember('user:1', 3600, function() {
            return ['name' => 'Jane', 'visits' => 0]; // This won't be called
        });
        
        $this->assertEquals('John', $user['name']);
        $this->assertEquals(1, $user['visits']);
    }
}
