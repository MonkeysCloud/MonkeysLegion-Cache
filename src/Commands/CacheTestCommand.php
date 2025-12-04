<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Commands;

use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;
use MonkeysLegion\Cache\CacheManager;

/**
 * Test cache operations
 * 
 * Usage:
 *   php ml cache:test
 *   php ml cache:test --store=redis
 */
#[CommandAttr('cache:test', 'Test cache operations')]
final class CacheTestCommand extends Command
{
    public function __construct(
        private CacheManager $cache
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $argv = $_SERVER['argv'] ?? [];
        $args = array_slice($argv, 2);
        $store = $this->parseStore($args);

        try {
            $storeName = $store ?? $this->cache->getDefaultDriver();
            $cacheStore = $this->cache->store($store);

            $this->info("Testing cache store: {$storeName}");
            $this->line(str_repeat('=', 60));
            $this->line('');

            $passed = 0;
            $failed = 0;

            // Test 1: Set and Get
            if ($this->testSetAndGet($cacheStore)) {
                $passed++;
            } else {
                $failed++;
            }

            // Test 2: Has
            if ($this->testHas($cacheStore)) {
                $passed++;
            } else {
                $failed++;
            }

            // Test 3: Delete
            if ($this->testDelete($cacheStore)) {
                $passed++;
            } else {
                $failed++;
            }

            // Test 4: Multiple operations
            if ($this->testMultiple($cacheStore)) {
                $passed++;
            } else {
                $failed++;
            }

            // Test 5: Increment/Decrement
            if ($this->testIncrement($cacheStore)) {
                $passed++;
            } else {
                $failed++;
            }

            // Test 6: TTL
            if ($this->testTTL($cacheStore)) {
                $passed++;
            } else {
                $failed++;
            }

            $this->line('');
            $this->line(str_repeat('=', 60));

            if ($failed === 0) {
                $this->info("✅  All tests passed! ({$passed}/{$passed})");
                return self::SUCCESS;
            } else {
                $this->error("❌  Some tests failed! Passed: {$passed}, Failed: {$failed}");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Test suite failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @param array<int, string> $args
     */
    private function parseStore(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--store=')) {
                return substr($arg, 8);
            }
        }
        return null;
    }

    private function testSetAndGet(mixed $store): bool
    {
        $this->line('Test 1: Set and Get');
        
        try {
            $key = 'test:' . uniqid();
            $value = 'test_value_' . time();

            $store->set($key, $value);
            $retrieved = $store->get($key);

            if ($retrieved === $value) {
                $this->line('  ✅  PASS');
                $store->delete($key);
                return true;
            } else {
                $this->line('  ❌  FAIL - Value mismatch');
                return false;
            }
        } catch (\Exception $e) {
            $this->line('  ❌  FAIL - ' . $e->getMessage());
            return false;
        }
    }

    private function testHas(mixed $store): bool
    {
        $this->line('Test 2: Has');
        
        try {
            $key = 'test:' . uniqid();

            if ($store->has($key)) {
                $this->line('  ❌  FAIL - Key should not exist');
                return false;
            }

            $store->set($key, 'value');

            if (!$store->has($key)) {
                $this->line('  ❌  FAIL - Key should exist');
                $store->delete($key);
                return false;
            }

            $this->line('  ✅  PASS');
            $store->delete($key);
            return true;
        } catch (\Exception $e) {
            $this->line('  ❌  FAIL - ' . $e->getMessage());
            return false;
        }
    }

    private function testDelete(mixed $store): bool
    {
        $this->line('Test 3: Delete');
        
        try {
            $key = 'test:' . uniqid();
            $store->set($key, 'value');
            $store->delete($key);

            if ($store->has($key)) {
                $this->line('  ❌  FAIL - Key was not deleted');
                return false;
            }

            $this->line('  ✅  PASS');
            return true;
        } catch (\Exception $e) {
            $this->line('  ❌  FAIL - ' . $e->getMessage());
            return false;
        }
    }

    private function testMultiple(mixed $store): bool
    {
        $this->line('Test 4: Multiple Operations');
        
        try {
            $keys = [
                'test:multi1:' . uniqid() => 'value1',
                'test:multi2:' . uniqid() => 'value2',
            ];

            $store->setMultiple($keys);
            $retrieved = $store->getMultiple(array_keys($keys));

            if ($retrieved != $keys) {
                $this->line('  ❌  FAIL - Values mismatch');
                $store->deleteMultiple(array_keys($keys));
                return false;
            }

            $store->deleteMultiple(array_keys($keys));
            $this->line('  ✅  PASS');
            return true;
        } catch (\Exception $e) {
            $this->line('  ❌  FAIL - ' . $e->getMessage());
            return false;
        }
    }

    private function testIncrement(mixed $store): bool
    {
        $this->line('Test 5: Increment/Decrement');
        
        try {
            $key = 'test:counter:' . uniqid();

            $val1 = $store->increment($key);
            $val2 = $store->increment($key);
            $val3 = $store->decrement($key);

            if ($val1 !== 1 || $val2 !== 2 || $val3 !== 1) {
                $this->line("  ❌  FAIL - Counter values incorrect ({$val1}, {$val2}, {$val3})");
                $store->delete($key);
                return false;
            }

            $this->line('  ✅  PASS');
            $store->delete($key);
            return true;
        } catch (\Exception $e) {
            $this->line('  ❌  FAIL - ' . $e->getMessage());
            return false;
        }
    }

    private function testTTL(mixed $store): bool
    {
        $this->line('Test 6: TTL Expiration');
        
        try {
            $key = 'test:ttl:' . uniqid();
            $store->set($key, 'value', 1);

            if (!$store->has($key)) {
                $this->line('  ❌  FAIL - Key should exist immediately');
                return false;
            }

            sleep(2);

            if ($store->has($key)) {
                $this->line('  ❌  FAIL - Key should have expired');
                $store->delete($key);
                return false;
            }

            $this->line('  ✅  PASS');
            return true;
        } catch (\Exception $e) {
            $this->line('  ❌  FAIL - ' . $e->getMessage());
            return false;
        }
    }
}
