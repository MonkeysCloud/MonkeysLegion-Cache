<?php

namespace MonkeysLegion\Cache\Tests;

use PHPUnit\Framework\TestCase;
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\Cache;

class HelperFunctionsTest extends TestCase
{
    private CacheManager $manager;

    protected function setUp(): void
    {
        $config = [
            'default' => 'array',
            'stores' => [
                'array' => [
                    'driver' => 'array',
                    'prefix' => 'test_',
                ],
            ],
        ];

        $this->manager = new CacheManager($config);
        Cache::setInstance($this->manager);
        
        // Clear cache before each test
        cache()->clear();
    }

    protected function tearDown(): void
    {
        cache()->clear();
    }

    public function testCacheHelperGet(): void
    {
        cache()->set('key', 'value');
        $this->assertEquals('value', cache('key'));
    }

    public function testCacheHelperSet(): void
    {
        cache('key', 'value');
        $this->assertEquals('value', cache('key'));
    }

    public function testCacheHelperSetArray(): void
    {
        cache(['key1' => 'value1', 'key2' => 'value2']);
        
        $this->assertEquals('value1', cache('key1'));
        $this->assertEquals('value2', cache('key2'));
    }

    public function testCacheHelperNoArgs(): void
    {
        $this->assertInstanceOf(CacheManager::class, cache());
    }

    public function testCacheRememberHelper(): void
    {
        $callCount = 0;
        
        $value = cache_remember('key', 3600, function() use (&$callCount) {
            $callCount++;
            return 'computed';
        });
        
        $this->assertEquals('computed', $value);
        $this->assertEquals(1, $callCount);
        
        $value = cache_remember('key', 3600, function() use (&$callCount) {
            $callCount++;
            return 'computed';
        });
        
        $this->assertEquals('computed', $value);
        $this->assertEquals(1, $callCount);
    }

    public function testCacheForeverHelper(): void
    {
        $callCount = 0;
        
        $value = cache_forever('key', function() use (&$callCount) {
            $callCount++;
            return 'forever';
        });
        
        $this->assertEquals('forever', $value);
        $this->assertEquals(1, $callCount);
        
        $value = cache_forever('key', function() use (&$callCount) {
            $callCount++;
            return 'forever';
        });
        
        $this->assertEquals('forever', $value);
        $this->assertEquals(1, $callCount);
    }

    public function testCacheForgetHelper(): void
    {
        cache('key', 'value');
        $this->assertTrue(cache_has('key'));
        
        cache_forget('key');
        $this->assertFalse(cache_has('key'));
    }

    public function testCacheFlushHelper(): void
    {
        cache('key1', 'value1');
        cache('key2', 'value2');
        
        cache_flush();
        
        $this->assertFalse(cache_has('key1'));
        $this->assertFalse(cache_has('key2'));
    }

    public function testCacheHasHelper(): void
    {
        $this->assertFalse(cache_has('key'));
        
        cache('key', 'value');
        $this->assertTrue(cache_has('key'));
    }

    public function testCachePullHelper(): void
    {
        cache('key', 'value');
        
        $value = cache_pull('key');
        $this->assertEquals('value', $value);
        $this->assertFalse(cache_has('key'));
    }

    public function testCachePullWithDefault(): void
    {
        $value = cache_pull('missing', 'default');
        $this->assertEquals('default', $value);
    }

    public function testCacheAddHelper(): void
    {
        $this->assertTrue(cache_add('key', 'value'));
        $this->assertFalse(cache_add('key', 'new_value'));
        $this->assertEquals('value', cache('key'));
    }

    public function testCacheAddWithTTL(): void
    {
        cache_add('key', 'value', 3600);
        $this->assertEquals('value', cache('key'));
    }

    public function testMultipleHelpersCombined(): void
    {
        // Set values
        cache(['user:1' => 'John', 'user:2' => 'Jane']);
        
        // Check existence
        $this->assertTrue(cache_has('user:1'));
        $this->assertTrue(cache_has('user:2'));
        
        // Remember pattern
        $user = cache_remember('user:3', 3600, function() {
            return 'Bob';
        });
        $this->assertEquals('Bob', $user);
        
        // Pull
        $pulled = cache_pull('user:2');
        $this->assertEquals('Jane', $pulled);
        $this->assertFalse(cache_has('user:2'));
        
        // Forget
        cache_forget('user:1');
        $this->assertFalse(cache_has('user:1'));
        
        // Only user:3 should remain
        $this->assertTrue(cache_has('user:3'));
        
        // Flush all
        cache_flush();
        $this->assertFalse(cache_has('user:3'));
    }

    public function testHelperWithComplexData(): void
    {
        $data = [
            'name' => 'John',
            'age' => 30,
            'hobbies' => ['reading', 'gaming'],
        ];
        
        cache('user', $data);
        $retrieved = cache('user');
        
        $this->assertEquals($data, $retrieved);
    }

    public function testRememberWithExpensiveOperation(): void
    {
        $start = microtime(true);
        
        $result = cache_remember('expensive', 3600, function() {
            usleep(10000); // Simulate 10ms operation
            return 'result';
        });
        
        $firstCallTime = microtime(true) - $start;
        
        $start = microtime(true);
        
        $result2 = cache_remember('expensive', 3600, function() {
            usleep(10000);
            return 'result';
        });
        
        $secondCallTime = microtime(true) - $start;
        
        $this->assertEquals($result, $result2);
        $this->assertLessThan($firstCallTime, $secondCallTime);
    }
}
