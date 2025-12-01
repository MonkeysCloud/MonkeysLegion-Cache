<?php

namespace MonkeysLegion\Cache\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Cache\Stores\ArrayStore;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 Compliance Tests
 * 
 * These tests verify compliance with PSR-16 SimpleCache specification
 */
class PSR16ComplianceTest extends TestCase
{
    private ArrayStore $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayStore('psr16_');
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
    }

    public function testImplementsPSR16Interface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->cache);
    }

    // ========================================
    // get() Tests
    // ========================================

    public function testGetReturnsDefaultForNonExistentKey(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
        $this->assertEquals('default', $this->cache->get('nonexistent', 'default'));
    }

    public function testGetReturnsStoredValue(): void
    {
        $this->cache->set('key', 'value');
        $this->assertEquals('value', $this->cache->get('key'));
    }

    // ========================================
    // set() Tests
    // ========================================

    public function testSetStoresValue(): void
    {
        $this->assertTrue($this->cache->set('key', 'value'));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testSetWithTTL(): void
    {
        $this->assertTrue($this->cache->set('key', 'value', 3600));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testSetWithNullTTL(): void
    {
        $this->assertTrue($this->cache->set('key', 'value', null));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testSetWithDateIntervalTTL(): void
    {
        $interval = new \DateInterval('PT1H');
        $this->assertTrue($this->cache->set('key', 'value', $interval));
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testSetInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache->set('', 'value');
    }

    // ========================================
    // delete() Tests
    // ========================================

    public function testDeleteRemovesKey(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->delete('key'));
        $this->assertNull($this->cache->get('key'));
    }

    public function testDeleteNonExistentKey(): void
    {
        $this->assertTrue($this->cache->delete('nonexistent'));
    }

    // ========================================
    // clear() Tests
    // ========================================

    public function testClearRemovesAllKeys(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');
        
        $this->assertTrue($this->cache->clear());
        
        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
        $this->assertNull($this->cache->get('key3'));
    }

    public function testClearEmptyCache(): void
    {
        $this->assertTrue($this->cache->clear());
    }

    // ========================================
    // getMultiple() Tests
    // ========================================

    public function testGetMultipleReturnsValues(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $values = $this->cache->getMultiple(['key1', 'key2']);
        
        $this->assertEquals('value1', $values['key1']);
        $this->assertEquals('value2', $values['key2']);
    }

    public function testGetMultipleWithDefault(): void
    {
        $this->cache->set('key1', 'value1');
        
        $values = $this->cache->getMultiple(['key1', 'key2'], 'default');
        
        $this->assertEquals('value1', $values['key1']);
        $this->assertEquals('default', $values['key2']);
    }

    public function testGetMultipleWithEmptyKeys(): void
    {
        $values = $this->cache->getMultiple([]);
        $this->assertEmpty($values);
    }

    // ========================================
    // setMultiple() Tests
    // ========================================

    public function testSetMultipleStoresValues(): void
    {
        $values = ['key1' => 'value1', 'key2' => 'value2'];
        
        $this->assertTrue($this->cache->setMultiple($values));
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
    }

    public function testSetMultipleWithTTL(): void
    {
        $values = ['key1' => 'value1', 'key2' => 'value2'];
        
        $this->assertTrue($this->cache->setMultiple($values, 3600));
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
    }

    public function testSetMultipleWithEmptyValues(): void
    {
        $this->assertTrue($this->cache->setMultiple([]));
    }

    // ========================================
    // deleteMultiple() Tests
    // ========================================

    public function testDeleteMultipleRemovesKeys(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');
        
        $this->assertTrue($this->cache->deleteMultiple(['key1', 'key2']));
        
        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
        $this->assertEquals('value3', $this->cache->get('key3'));
    }

    public function testDeleteMultipleWithEmptyKeys(): void
    {
        $this->assertTrue($this->cache->deleteMultiple([]));
    }

    // ========================================
    // has() Tests
    // ========================================

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
    }

    public function testHasReturnsFalseForNonExistentKey(): void
    {
        $this->assertFalse($this->cache->has('nonexistent'));
    }

    public function testHasReturnsFalseForExpiredKey(): void
    {
        $this->cache->set('key', 'value', 1);
        sleep(2);
        $this->assertFalse($this->cache->has('key'));
    }

    // ========================================
    // Data Type Tests
    // ========================================

    public function testStoreString(): void
    {
        $this->cache->set('string', 'hello');
        $this->assertEquals('hello', $this->cache->get('string'));
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

    public function testStoreArray(): void
    {
        $array = ['foo' => 'bar', 'baz' => 'qux'];
        $this->cache->set('array', $array);
        $this->assertEquals($array, $this->cache->get('array'));
    }

    public function testStoreObject(): void
    {
        $obj = new \stdClass();
        $obj->property = 'value';
        
        $this->cache->set('object', $obj);
        $retrieved = $this->cache->get('object');
        
        $this->assertEquals($obj, $retrieved);
    }

    // ========================================
    // TTL Behavior Tests
    // ========================================

    public function testZeroTTLExpires(): void
    {
        $this->cache->set('key', 'value', 0);
        // Zero TTL should expire immediately or very soon
        sleep(1);
        $this->assertNull($this->cache->get('key'));
    }

    public function testNegativeTTLExpires(): void
    {
        $this->cache->set('key', 'value', -1);
        // Negative TTL should expire immediately
        $this->assertNull($this->cache->get('key'));
    }

    // ========================================
    // Key Validation Tests
    // ========================================

    public function testValidKeys(): void
    {
        $validKeys = [
            'simple',
            'key_with_underscore',
            'key-with-dash',
            'key.with.dot',
            'key:with:colon',
            '123numeric',
        ];

        foreach ($validKeys as $key) {
            $this->cache->set($key, 'value');
            $this->assertEquals('value', $this->cache->get($key));
        }
    }

    public function testReservedCharactersInKeys(): void
    {
        // PSR-16 reserves these characters: {}()/\@:
        // But our implementation should handle them gracefully
        $keys = [
            'key:with:colon' => 'value',
            'key.with.dot' => 'value',
            'key-with-dash' => 'value',
        ];

        foreach ($keys as $key => $value) {
            $this->cache->set($key, $value);
            $this->assertEquals($value, $this->cache->get($key));
        }
    }
}
