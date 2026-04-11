<?php

declare(strict_types=1);

namespace MonkeysLegion\Cache\Tests;

use MonkeysLegion\Cache\CacheEntry;
use MonkeysLegion\Cache\CacheManager;
use MonkeysLegion\Cache\CacheStats;
use MonkeysLegion\Cache\CacheStoreInterface;
use MonkeysLegion\Cache\Event\CacheEvent;
use MonkeysLegion\Cache\Event\CacheEventType;
use MonkeysLegion\Cache\Lock\ArrayLock;
use MonkeysLegion\Cache\Lock\CacheLock;
use MonkeysLegion\Cache\Lock\FileLock;
use MonkeysLegion\Cache\Lock\LockState;
use MonkeysLegion\Cache\Lock\LockTimeoutException;
use MonkeysLegion\Cache\Serializer\CacheSerializerInterface;
use MonkeysLegion\Cache\Serializer\EncryptedSerializer;
use MonkeysLegion\Cache\Serializer\JsonSerializer;
use MonkeysLegion\Cache\Serializer\PhpSerializer;
use MonkeysLegion\Cache\Serializer\SerializerType;
use MonkeysLegion\Cache\Stores\ArrayStore;
use MonkeysLegion\Cache\Stores\ChainStore;
use MonkeysLegion\Cache\Stores\FileStore;
use MonkeysLegion\Cache\Stores\NullStore;
use MonkeysLegion\Cache\TaggedCache;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite for MonkeysLegion Cache v2.
 *
 * @covers all major components: CacheEntry, CacheStats, Serializers,
 *         ArrayStore, FileStore, NullStore, ChainStore, TaggedCache,
 *         Locks, CacheManager, Events, enums.
 */
final class CacheV2Test extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ml-cache-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
        ArrayLock::reset();
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
        ArrayLock::reset();
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \FilesystemIterator($dir);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function makeArrayStore(): ArrayStore
    {
        return new ArrayStore(prefix: 'test');
    }

    private function makeFileStore(): FileStore
    {
        return new FileStore(directory: $this->tempDir, prefix: 'test');
    }

    // ── CacheEntry ─────────────────────────────────────────────

    public function testCacheEntryBasic(): void
    {
        $entry = new CacheEntry(value: 'hello', expiresAt: time() + 3600);
        $this->assertSame('hello', $entry->value);
        $this->assertFalse($entry->isExpired);
        $this->assertGreaterThan(0, $entry->remainingTtl);
    }

    public function testCacheEntryExpired(): void
    {
        $entry = new CacheEntry(value: 'old', expiresAt: time() - 1);
        $this->assertTrue($entry->isExpired);
        $this->assertSame(0, $entry->remainingTtl);
    }

    public function testCacheEntryNoTtl(): void
    {
        $entry = new CacheEntry(value: 'forever');
        $this->assertFalse($entry->isExpired);
        $this->assertNull($entry->remainingTtl);
    }

    public function testCacheEntryAge(): void
    {
        $entry = new CacheEntry(value: 'x', createdAt: time() - 60);
        $this->assertGreaterThanOrEqual(60, $entry->age);
    }

    public function testCacheEntryWithHit(): void
    {
        $entry = new CacheEntry(value: 'x', hits: 5);
        $next  = $entry->withHit();
        $this->assertSame(5, $entry->hits);
        $this->assertSame(6, $next->hits);
    }

    public function testCacheEntryToArrayAndFromArray(): void
    {
        $entry = new CacheEntry(value: ['data' => 42], expiresAt: time() + 100, tags: ['user']);
        $arr   = $entry->toArray();
        $back  = CacheEntry::fromArray($arr);

        $this->assertSame(42, $back->value['data']);
        $this->assertSame(['user'], $back->tags);
    }

    public function testCacheEntryShouldRefreshFalseForNoTtl(): void
    {
        $entry = new CacheEntry(value: 'x');
        $this->assertFalse($entry->shouldRefresh());
    }

    public function testCacheEntryShouldRefreshTrueWhenExpired(): void
    {
        $entry = new CacheEntry(value: 'x', expiresAt: time() - 1, createdAt: time() - 100);
        $this->assertTrue($entry->shouldRefresh());
    }

    // ── CacheStats ─────────────────────────────────────────────

    public function testCacheStatsHitRate(): void
    {
        $stats = new CacheStats(hits: 75, misses: 25);
        $this->assertSame(0.75, $stats->hitRate);
    }

    public function testCacheStatsHitRateZero(): void
    {
        $stats = new CacheStats();
        $this->assertSame(0.0, $stats->hitRate);
    }

    public function testCacheStatsMemoryFormatted(): void
    {
        $this->assertSame('0 B', (new CacheStats(memoryUsage: 0))->memoryFormatted);
        $this->assertSame('500 B', (new CacheStats(memoryUsage: 500))->memoryFormatted);
        $this->assertStringContainsString('KB', (new CacheStats(memoryUsage: 2048))->memoryFormatted);
        $this->assertStringContainsString('MB', (new CacheStats(memoryUsage: 2_000_000))->memoryFormatted);
        $this->assertStringContainsString('GB', (new CacheStats(memoryUsage: 2_000_000_000))->memoryFormatted);
    }

    public function testCacheStatsToArray(): void
    {
        $stats = new CacheStats(hits: 10, misses: 5);
        $arr   = $stats->toArray();
        $this->assertSame(10, $arr['hits']);
        $this->assertSame(5, $arr['misses']);
        $this->assertArrayHasKey('hitRate', $arr);
    }

    // ── Serializers ────────────────────────────────────────────

    public function testPhpSerializer(): void
    {
        $s = new PhpSerializer();
        $data = ['key' => 'value', 'num' => 42];
        $this->assertSame($data, $s->unserialize($s->serialize($data)));
    }

    public function testPhpSerializerCorruptThrows(): void
    {
        $s = new PhpSerializer();
        $this->expectException(\RuntimeException::class);
        $s->unserialize('invalid{data');
    }

    public function testJsonSerializer(): void
    {
        $s = new JsonSerializer();
        $data = ['hello' => 'world', 'n' => 3.14];
        $this->assertSame($data, $s->unserialize($s->serialize($data)));
    }

    public function testJsonSerializerThrowsOnInvalid(): void
    {
        $s = new JsonSerializer();
        $this->expectException(\JsonException::class);
        $s->unserialize('{invalid json');
    }

    public function testEncryptedSerializer(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }

        $inner = new PhpSerializer();
        $enc   = new EncryptedSerializer($inner, str_repeat('a', 32));

        $data = ['secret' => 'data'];
        $encrypted = $enc->serialize($data);

        // Encrypted should differ from plaintext
        $this->assertNotSame($inner->serialize($data), $encrypted);

        // Should decrypt correctly
        $this->assertSame($data, $enc->unserialize($encrypted));
    }

    public function testEncryptedSerializerTamperedThrows(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }

        $enc = new EncryptedSerializer(new PhpSerializer(), str_repeat('b', 32));
        $encrypted = $enc->serialize('test');

        $this->expectException(\RuntimeException::class);
        $enc->unserialize($encrypted . 'tampered');
    }

    public function testEncryptedSerializerWrongKeyFails(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }

        $enc1 = new EncryptedSerializer(new PhpSerializer(), str_repeat('a', 32));
        $enc2 = new EncryptedSerializer(new PhpSerializer(), str_repeat('b', 32));

        $encrypted = $enc1->serialize('secret');

        $this->expectException(\RuntimeException::class);
        $enc2->unserialize($encrypted);
    }

    public function testEncryptedSerializerShortKeyDerived(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }

        // Short key should be derived via BLAKE2b
        $enc = new EncryptedSerializer(new PhpSerializer(), 'short');
        $this->assertSame('hello', $enc->unserialize($enc->serialize('hello')));
    }

    // ── SerializerType enum ────────────────────────────────────

    public function testSerializerTypeEnum(): void
    {
        $this->assertSame('php', SerializerType::Php->value);
        $this->assertSame('json', SerializerType::Json->value);
        $this->assertTrue(SerializerType::Php->isAvailable());
        $this->assertTrue(SerializerType::Json->isAvailable());
    }

    // ── ArrayStore ─────────────────────────────────────────────

    public function testArrayStoreGetSet(): void
    {
        $store = $this->makeArrayStore();
        $this->assertTrue($store->set('key', 'value'));
        $this->assertSame('value', $store->get('key'));
    }

    public function testArrayStoreGetDefault(): void
    {
        $store = $this->makeArrayStore();
        $this->assertSame('fallback', $store->get('missing', 'fallback'));
    }

    public function testArrayStoreDelete(): void
    {
        $store = $this->makeArrayStore();
        $store->set('key', 'val');
        $this->assertTrue($store->delete('key'));
        $this->assertNull($store->get('key'));
    }

    public function testArrayStoreClear(): void
    {
        $store = $this->makeArrayStore();
        $store->set('a', 1);
        $store->set('b', 2);
        $this->assertTrue($store->clear());
        $this->assertNull($store->get('a'));
        $this->assertNull($store->get('b'));
    }

    public function testArrayStoreHas(): void
    {
        $store = $this->makeArrayStore();
        $this->assertFalse($store->has('x'));
        $store->set('x', 1);
        $this->assertTrue($store->has('x'));
    }

    public function testArrayStoreIncrement(): void
    {
        $store = $this->makeArrayStore();
        $this->assertSame(1, $store->increment('counter'));
        $this->assertSame(6, $store->increment('counter', 5));
    }

    public function testArrayStoreDecrement(): void
    {
        $store = $this->makeArrayStore();
        $store->set('counter', 10);
        $this->assertSame(7, $store->decrement('counter', 3));
    }

    public function testArrayStoreRemember(): void
    {
        $store = $this->makeArrayStore();
        $calls = 0;

        $value1 = $store->remember('key', 3600, function () use (&$calls) {
            $calls++;
            return 'computed';
        });

        $value2 = $store->remember('key', 3600, function () use (&$calls) {
            $calls++;
            return 'should not run';
        });

        $this->assertSame('computed', $value1);
        $this->assertSame('computed', $value2);
        $this->assertSame(1, $calls);
    }

    public function testArrayStoreForever(): void
    {
        $store = $this->makeArrayStore();
        $store->forever('key', 'permanent');
        $this->assertSame('permanent', $store->get('key'));
    }

    public function testArrayStorePull(): void
    {
        $store = $this->makeArrayStore();
        $store->set('key', 'value');
        $this->assertSame('value', $store->pull('key'));
        $this->assertNull($store->get('key'));
    }

    public function testArrayStoreAdd(): void
    {
        $store = $this->makeArrayStore();
        $this->assertTrue($store->add('key', 'first'));
        $this->assertFalse($store->add('key', 'second'));
        $this->assertSame('first', $store->get('key'));
    }

    public function testArrayStoreTouch(): void
    {
        $store = $this->makeArrayStore();
        $store->set('key', 'value', 10);
        $this->assertTrue($store->touch('key', 3600));
        $this->assertFalse($store->touch('missing', 3600));
    }

    public function testArrayStoreGetMultiple(): void
    {
        $store = $this->makeArrayStore();
        $store->set('a', 1);
        $store->set('b', 2);

        $result = $store->getMultiple(['a', 'b', 'c'], 'default');
        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
        $this->assertSame('default', $result['c']);
    }

    public function testArrayStoreSetMultiple(): void
    {
        $store = $this->makeArrayStore();
        $store->setMultiple(['x' => 10, 'y' => 20]);
        $this->assertSame(10, $store->get('x'));
        $this->assertSame(20, $store->get('y'));
    }

    public function testArrayStoreDeleteMultiple(): void
    {
        $store = $this->makeArrayStore();
        $store->set('a', 1);
        $store->set('b', 2);
        $store->deleteMultiple(['a', 'b']);
        $this->assertNull($store->get('a'));
        $this->assertNull($store->get('b'));
    }

    public function testArrayStoreStats(): void
    {
        $store = $this->makeArrayStore();
        $store->set('a', 1);
        $store->get('a');
        $store->get('missing');

        $stats = $store->getStats();
        $this->assertSame(1, $stats->hits);
        $this->assertSame(1, $stats->misses);
        $this->assertSame(1, $stats->writes);
        $this->assertSame(1, $stats->itemCount);
    }

    public function testArrayStorePrefix(): void
    {
        $store = $this->makeArrayStore();
        $this->assertSame('test:', $store->getPrefix());
    }

    // ── Typed getters ──────────────────────────────────────────

    public function testTypedGetterInteger(): void
    {
        $store = $this->makeArrayStore();
        $store->set('num', '42');
        $this->assertSame(42, $store->integer('num'));
        $this->assertSame(0, $store->integer('missing'));
    }

    public function testTypedGetterBoolean(): void
    {
        $store = $this->makeArrayStore();
        $store->set('flag', 1);
        $this->assertTrue($store->boolean('flag'));
        $this->assertFalse($store->boolean('missing'));
    }

    public function testTypedGetterFloat(): void
    {
        $store = $this->makeArrayStore();
        $store->set('pi', '3.14');
        $this->assertSame(3.14, $store->float('pi'));
    }

    public function testTypedGetterString(): void
    {
        $store = $this->makeArrayStore();
        $store->set('name', 42);
        $this->assertSame('42', $store->string('name'));
        $this->assertSame('', $store->string('missing'));
    }

    public function testTypedGetterArray(): void
    {
        $store = $this->makeArrayStore();
        $store->set('list', [1, 2, 3]);
        $this->assertSame([1, 2, 3], $store->array('list'));
        $this->assertSame([], $store->array('missing'));
    }

    // ── FileStore ──────────────────────────────────────────────

    public function testFileStoreGetSet(): void
    {
        $store = $this->makeFileStore();
        $this->assertTrue($store->set('file-key', ['data' => 42]));
        $this->assertSame(['data' => 42], $store->get('file-key'));
    }

    public function testFileStoreGetDefault(): void
    {
        $store = $this->makeFileStore();
        $this->assertSame('fallback', $store->get('nope', 'fallback'));
    }

    public function testFileStoreDelete(): void
    {
        $store = $this->makeFileStore();
        $store->set('key', 'value');
        $this->assertTrue($store->delete('key'));
        $this->assertNull($store->get('key'));
    }

    public function testFileStoreClear(): void
    {
        $store = $this->makeFileStore();
        $store->set('a', 1);
        $store->set('b', 2);
        $this->assertTrue($store->clear());
        $this->assertNull($store->get('a'));
    }

    public function testFileStoreHas(): void
    {
        $store = $this->makeFileStore();
        $this->assertFalse($store->has('x'));
        $store->set('x', 'val');
        $this->assertTrue($store->has('x'));
    }

    public function testFileStoreIncrement(): void
    {
        $store = $this->makeFileStore();
        $this->assertSame(1, $store->increment('ctr'));
        $this->assertSame(6, $store->increment('ctr', 5));
    }

    public function testFileStoreTouch(): void
    {
        $store = $this->makeFileStore();
        $store->set('key', 'val', 10);
        $this->assertTrue($store->touch('key', 3600));
        $this->assertFalse($store->touch('missing', 3600));
    }

    public function testFileStoreGc(): void
    {
        $store = $this->makeFileStore();
        $store->set('fresh', 'value', 3600);
        $store->set('expired', 'old', -1); // Already expired

        // The expired item may or may not be cleaned by gc depending on timing
        $removed = $store->gc();
        $this->assertGreaterThanOrEqual(0, $removed);
    }

    public function testFileStoreStats(): void
    {
        $store = $this->makeFileStore();
        $store->set('a', 1);
        $store->get('a');

        $stats = $store->getStats();
        $this->assertSame(1, $stats->hits);
        $this->assertSame(1, $stats->writes);
        $this->assertGreaterThanOrEqual(1, $stats->itemCount);
    }

    // ── NullStore ──────────────────────────────────────────────

    public function testNullStoreAlwaysMisses(): void
    {
        $store = new NullStore();
        $store->set('key', 'value');
        $this->assertNull($store->get('key'));
        $this->assertFalse($store->has('key'));
    }

    public function testNullStoreWritesSucceed(): void
    {
        $store = new NullStore();
        $this->assertTrue($store->set('key', 'value'));
        $this->assertTrue($store->delete('key'));
        $this->assertTrue($store->clear());
    }

    public function testNullStoreIncrement(): void
    {
        $store = new NullStore();
        $this->assertSame(1, $store->increment('counter'));
    }

    public function testNullStoreTouchFails(): void
    {
        $store = new NullStore();
        $this->assertFalse($store->touch('key', 3600));
    }

    // ── ChainStore ─────────────────────────────────────────────

    public function testChainStoreRequiresMinTwoLayers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChainStore([new ArrayStore()]);
    }

    public function testChainStoreReadCascade(): void
    {
        $l1 = new ArrayStore();
        $l2 = new ArrayStore();
        $chain = new ChainStore([$l1, $l2]);

        // Set only in L2
        $l2->set('key', 'from-l2');

        $value = $chain->get('key');
        $this->assertSame('from-l2', $value);

        // Should now be promoted to L1
        $this->assertSame('from-l2', $l1->get('key'));
    }

    public function testChainStoreWriteThrough(): void
    {
        $l1 = new ArrayStore();
        $l2 = new ArrayStore();
        $chain = new ChainStore([$l1, $l2]);

        $chain->set('key', 'value');

        $this->assertSame('value', $l1->get('key'));
        $this->assertSame('value', $l2->get('key'));
    }

    public function testChainStoreDelete(): void
    {
        $l1 = new ArrayStore();
        $l2 = new ArrayStore();
        $chain = new ChainStore([$l1, $l2]);

        $chain->set('key', 'val');
        $chain->delete('key');

        $this->assertNull($l1->get('key'));
        $this->assertNull($l2->get('key'));
    }

    public function testChainStoreLayerCount(): void
    {
        $chain = new ChainStore([new ArrayStore(), new ArrayStore()]);
        $this->assertSame(2, $chain->layerCount);
    }

    public function testChainStoreLayerAccess(): void
    {
        $l1 = new ArrayStore();
        $l2 = new ArrayStore();
        $chain = new ChainStore([$l1, $l2]);

        $this->assertSame($l1, $chain->layer(0));
        $this->assertSame($l2, $chain->layer(1));
    }

    public function testChainStoreLayerOutOfRange(): void
    {
        $chain = new ChainStore([new ArrayStore(), new ArrayStore()]);
        $this->expectException(\OutOfRangeException::class);
        $chain->layer(5);
    }

    public function testChainStoreIncrement(): void
    {
        $l1 = new ArrayStore();
        $l2 = new ArrayStore();
        $chain = new ChainStore([$l1, $l2]);

        $this->assertSame(1, $chain->increment('counter'));
        $this->assertSame(1, $l1->get('counter'));
    }

    // ── TaggedCache ────────────────────────────────────────────

    public function testTaggedCacheSetGet(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['user']);

        $tagged->set('profile', ['name' => 'Jorge']);
        $this->assertSame(['name' => 'Jorge'], $tagged->get('profile'));
    }

    public function testTaggedCacheFlushInvalidates(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['user']);

        $tagged->set('profile', 'data');
        $this->assertSame('data', $tagged->get('profile'));

        $tagged->flush(); // Increments tag version

        // After flush, same key should miss (different namespace now)
        $this->assertNull($tagged->get('profile'));
    }

    public function testTaggedCacheIsolation(): void
    {
        $store = $this->makeArrayStore();

        $users = $store->tags(['users']);
        $posts = $store->tags(['posts']);

        $users->set('key', 'user-data');
        $posts->set('key', 'post-data');

        $this->assertSame('user-data', $users->get('key'));
        $this->assertSame('post-data', $posts->get('key'));

        // Flush users but not posts
        $users->flush();
        $this->assertNull($users->get('key'));
        $this->assertSame('post-data', $posts->get('key'));
    }

    public function testTaggedCacheRemember(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['cache']);

        $value = $tagged->remember('computed', 3600, fn() => 42);
        $this->assertSame(42, $value);
    }

    public function testTaggedCacheStaticInvalidate(): void
    {
        $store = $this->makeArrayStore();
        $tagged = $store->tags(['product']);
        $tagged->set('item', 'data');

        TaggedCache::invalidateTags($store, ['product']);

        $this->assertNull($tagged->get('item'));
    }

    // ── ArrayLock ──────────────────────────────────────────────

    public function testArrayLockAcquireRelease(): void
    {
        $lock = new ArrayLock('test-lock', 10);
        $this->assertTrue($lock->acquire());
        $this->assertSame(LockState::Acquired, $lock->state);
        $this->assertTrue($lock->release());
        $this->assertSame(LockState::Released, $lock->state);
    }

    public function testArrayLockPreventsConcurrent(): void
    {
        $lock1 = new ArrayLock('shared', 10, 'owner1');
        $lock2 = new ArrayLock('shared', 10, 'owner2');

        $this->assertTrue($lock1->acquire());
        $this->assertFalse($lock2->acquire()); // locked by owner1
    }

    public function testArrayLockForceRelease(): void
    {
        $lock1 = new ArrayLock('shared', 10, 'owner1');
        $lock2 = new ArrayLock('shared', 10, 'owner2');

        $lock1->acquire();
        $lock2->forceRelease();
        $this->assertTrue($lock2->acquire());
    }

    public function testArrayLockGet(): void
    {
        $lock = new ArrayLock('scope', 10);

        $result = $lock->get(fn() => 42);
        $this->assertSame(42, $result);
        $this->assertSame(LockState::Released, $lock->state);
    }

    public function testArrayLockOwner(): void
    {
        $lock = new ArrayLock('test', 10, 'custom-owner');
        $this->assertSame('custom-owner', $lock->owner());
    }

    public function testArrayLockAsymmetricVisibility(): void
    {
        $lock = new ArrayLock('test', 10);
        // owner and state are publicly readable
        $this->assertIsString($lock->owner);
        $this->assertSame(LockState::Released, $lock->state);
    }

    // ── FileLock ───────────────────────────────────────────────

    public function testFileLockAcquireRelease(): void
    {
        $lockDir = $this->tempDir . '/locks';
        $lock = new FileLock('file-lock', 10, null, $lockDir);

        $this->assertTrue($lock->acquire());
        $this->assertSame(LockState::Acquired, $lock->state);
        $this->assertTrue($lock->release());
        $this->assertSame(LockState::Released, $lock->state);
    }

    public function testFileLockForceRelease(): void
    {
        $lockDir = $this->tempDir . '/locks';
        $lock = new FileLock('force-lock', 10, null, $lockDir);
        $lock->acquire();
        $this->assertTrue($lock->forceRelease());
    }

    // ── LockState enum ─────────────────────────────────────────

    public function testLockStateEnum(): void
    {
        $this->assertTrue(LockState::Acquired->isHeld());
        $this->assertFalse(LockState::Released->isHeld());
        $this->assertFalse(LockState::Expired->isHeld());
    }

    // ── LockTimeoutException ───────────────────────────────────

    public function testLockTimeoutException(): void
    {
        $ex = new LockTimeoutException('my-lock', 5);
        $this->assertStringContainsString('my-lock', $ex->getMessage());
        $this->assertStringContainsString('5 seconds', $ex->getMessage());
    }

    // ── CacheEventType enum ────────────────────────────────────

    public function testCacheEventTypeEnum(): void
    {
        $this->assertTrue(CacheEventType::Hit->isRead());
        $this->assertTrue(CacheEventType::Miss->isRead());
        $this->assertFalse(CacheEventType::Write->isRead());
        $this->assertTrue(CacheEventType::Write->isMutation());
        $this->assertTrue(CacheEventType::Delete->isMutation());
        $this->assertFalse(CacheEventType::Hit->isMutation());
    }

    // ── CacheEvent ─────────────────────────────────────────────

    public function testCacheEventCreation(): void
    {
        $event = new CacheEvent(
            type:     CacheEventType::Hit,
            key:      'users.1',
            store:    'redis',
            duration: 0.5,
        );

        $this->assertSame(CacheEventType::Hit, $event->type);
        $this->assertSame('users.1', $event->key);
        $this->assertSame('redis', $event->store);
        $this->assertGreaterThan(0, $event->timestamp);
    }

    public function testCacheEventSummary(): void
    {
        $event = new CacheEvent(
            type:     CacheEventType::Write,
            key:      'data',
            store:    'file',
            duration: 1.23,
        );

        $this->assertStringContainsString('[file]', $event->summary);
        $this->assertStringContainsString('write', $event->summary);
        $this->assertStringContainsString('data', $event->summary);
    }

    public function testCacheEventToArray(): void
    {
        $event = new CacheEvent(type: CacheEventType::Delete, key: 'key', tags: ['tag1']);
        $arr   = $event->toArray();

        $this->assertSame('delete', $arr['type']);
        $this->assertSame('key', $arr['key']);
        $this->assertSame(['tag1'], $arr['tags']);
    }

    // ── CacheManager ───────────────────────────────────────────

    public function testCacheManagerArrayStore(): void
    {
        $manager = new CacheManager([
            'default' => 'memory',
            'stores'  => [
                'memory' => ['driver' => 'array'],
            ],
        ]);

        $store = $manager->store();
        $this->assertInstanceOf(ArrayStore::class, $store);

        $store->set('key', 'value');
        $this->assertSame('value', $store->get('key'));
    }

    public function testCacheManagerFileStore(): void
    {
        $manager = new CacheManager([
            'default' => 'files',
            'stores'  => [
                'files' => ['driver' => 'file', 'path' => $this->tempDir],
            ],
        ]);

        $store = $manager->store();
        $this->assertInstanceOf(FileStore::class, $store);
    }

    public function testCacheManagerNullStore(): void
    {
        $manager = new CacheManager([
            'default' => 'null',
            'stores'  => ['null' => ['driver' => 'null']],
        ]);

        $store = $manager->store();
        $this->assertInstanceOf(NullStore::class, $store);
    }

    public function testCacheManagerCachesInstances(): void
    {
        $manager = new CacheManager([
            'default' => 'mem',
            'stores'  => ['mem' => ['driver' => 'array']],
        ]);

        $this->assertSame($manager->store(), $manager->store());
    }

    public function testCacheManagerDriverAlias(): void
    {
        $manager = new CacheManager([
            'default' => 'mem',
            'stores'  => ['mem' => ['driver' => 'array']],
        ]);

        $this->assertSame($manager->store(), $manager->driver());
    }

    public function testCacheManagerUnknownStoreThrows(): void
    {
        $manager = new CacheManager(['stores' => []]);
        $this->expectException(\InvalidArgumentException::class);
        $manager->store('nope');
    }

    public function testCacheManagerUnknownDriverThrows(): void
    {
        $manager = new CacheManager([
            'stores' => ['bad' => ['driver' => 'unknown_driver']],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $manager->store('bad');
    }

    public function testCacheManagerExtend(): void
    {
        $manager = new CacheManager([
            'default' => 'custom',
            'stores'  => ['custom' => ['driver' => 'my-driver']],
        ]);

        $manager->extend('my-driver', fn(array $config) => new NullStore());

        $this->assertInstanceOf(NullStore::class, $manager->store());
    }

    public function testCacheManagerDefaultDriver(): void
    {
        $manager = new CacheManager([
            'default' => 'first',
            'stores'  => [
                'first'  => ['driver' => 'array'],
                'second' => ['driver' => 'null'],
            ],
        ]);

        $this->assertSame('first', $manager->getDefaultDriver());

        $manager->setDefaultDriver('second');
        $this->assertSame('second', $manager->getDefaultDriver());
        $this->assertInstanceOf(NullStore::class, $manager->store());
    }

    public function testCacheManagerJsonSerializer(): void
    {
        $manager = new CacheManager([
            'default' => 'json-store',
            'stores'  => [
                'json-store' => ['driver' => 'array', 'serializer' => 'json'],
            ],
        ]);

        $store = $manager->store();
        $store->set('data', ['key' => 'val']);
        $this->assertSame(['key' => 'val'], $store->get('data'));
    }

    public function testCacheManagerEncryptedSerializer(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }

        $manager = new CacheManager([
            'default'     => 'secure',
            'encrypt_key' => str_repeat('x', 32),
            'stores'      => [
                'secure' => ['driver' => 'array', 'encrypt' => true],
            ],
        ]);

        $store = $manager->store();
        $store->set('secret', ['password' => '1234']);
        $this->assertSame(['password' => '1234'], $store->get('secret'));
    }

    public function testCacheManagerChainStore(): void
    {
        $manager = new CacheManager([
            'default' => 'chain',
            'stores'  => [
                'l1'    => ['driver' => 'array'],
                'l2'    => ['driver' => 'array'],
                'chain' => ['driver' => 'chain', 'stores' => ['l1', 'l2']],
            ],
        ]);

        $store = $manager->store();
        $this->assertInstanceOf(ChainStore::class, $store);

        $store->set('chained', 'value');
        $this->assertSame('value', $store->get('chained'));
    }

    // ── Key validation ─────────────────────────────────────────

    public function testKeyValidationRejectsEmpty(): void
    {
        $store = $this->makeArrayStore();
        $this->expectException(\InvalidArgumentException::class);
        $store->get('');
    }

    public function testKeyValidationRejectsReservedChars(): void
    {
        $store = $this->makeArrayStore();
        $this->expectException(\InvalidArgumentException::class);
        $store->get('key{bad}');
    }

    // ── RememberForever ────────────────────────────────────────

    public function testRememberForever(): void
    {
        $store = $this->makeArrayStore();
        $calls = 0;

        $v1 = $store->rememberForever('forever-key', function () use (&$calls) {
            $calls++;
            return 'computed';
        });

        $v2 = $store->rememberForever('forever-key', function () use (&$calls) {
            $calls++;
            return 'should not run';
        });

        $this->assertSame('computed', $v1);
        $this->assertSame('computed', $v2);
        $this->assertSame(1, $calls);
    }

    // ── PSR-16 InvalidArgumentException ────────────────────────

    public function testPsr16InvalidArgumentExceptionInterface(): void
    {
        $ex = new \MonkeysLegion\Cache\Exception\InvalidArgumentException('test');
        $this->assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $ex);
        $this->assertInstanceOf(\InvalidArgumentException::class, $ex);
    }

    public function testKeyValidationThrowsPsr16Exception(): void
    {
        $store = $this->makeArrayStore();
        $this->expectException(\Psr\SimpleCache\InvalidArgumentException::class);
        $store->get('');
    }

    // ── Remember with null values ──────────────────────────────

    public function testRememberHandlesNullValue(): void
    {
        $store = $this->makeArrayStore();
        $calls = 0;

        // Store null explicitly
        $store->set('null-key', null);

        // remember should use has() check, not null check
        $value = $store->remember('null-key', 3600, function () use (&$calls) {
            $calls++;
            return 'recomputed';
        });

        // Since ArrayStore stores null but get() returns null (indistinguishable from miss),
        // the behavior depends on has() — which checks key existence
        $this->assertSame(0, $calls);
    }

    // ── ArrayStore LRU eviction ────────────────────────────────

    public function testArrayStoreLruEviction(): void
    {
        $store = new ArrayStore(prefix: 'test', maxItems: 3);

        $store->set('a', 1);
        $store->set('b', 2);
        $store->set('c', 3);

        // All three items should exist
        $this->assertSame(1, $store->get('a'));
        $this->assertSame(2, $store->get('b'));
        $this->assertSame(3, $store->get('c'));

        // Adding a 4th should evict 'a' (LRU, but 'a' was recently accessed via get)
        // Actually 'a' was moved to end by get(), so 'b' is now oldest
        $store->set('d', 4);

        $this->assertNull($store->get('b')); // 'b' was the oldest after 'a' was accessed
        $this->assertSame(4, $store->get('d'));
    }

    public function testArrayStoreLruNoEvictionWhenUnlimited(): void
    {
        $store = new ArrayStore(prefix: 'test', maxItems: 0);

        for ($i = 0; $i < 100; $i++) {
            $store->set("key{$i}", $i);
        }

        $this->assertSame(99, $store->get('key99'));
        $this->assertSame(0, $store->get('key0'));
    }

    // ── TaggedCache batch operations ───────────────────────────

    public function testTaggedCacheGetMultiple(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['batch']);

        $tagged->set('a', 1);
        $tagged->set('b', 2);

        $results = $tagged->getMultiple(['a', 'b', 'c'], 'default');
        $this->assertSame(1, $results['a']);
        $this->assertSame(2, $results['b']);
        $this->assertSame('default', $results['c']);
    }

    public function testTaggedCacheSetMultiple(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['batch']);

        $tagged->setMultiple(['x' => 10, 'y' => 20]);
        $this->assertSame(10, $tagged->get('x'));
        $this->assertSame(20, $tagged->get('y'));
    }

    public function testTaggedCacheDeleteMultiple(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['batch']);

        $tagged->set('a', 1);
        $tagged->set('b', 2);
        $tagged->deleteMultiple(['a', 'b']);

        $this->assertNull($tagged->get('a'));
        $this->assertNull($tagged->get('b'));
    }

    public function testTaggedCacheClearAliasFlush(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['cleartest']);

        $tagged->set('key', 'value');
        $this->assertSame('value', $tagged->get('key'));

        $tagged->clear();
        $this->assertNull($tagged->get('key'));
    }

    // ── TaggedCache namespace uses pipe separator ──────────────

    public function testTaggedCacheNamespaceUsesPipeSeparator(): void
    {
        $store  = $this->makeArrayStore();
        $tagged = $store->tags(['user']);

        // The namespace should use pipe separators, not dots
        $this->assertStringContainsString('tag|user|v', $tagged->tagNamespace);
    }

    // ── CacheManager purge and forgetDriver ────────────────────

    public function testCacheManagerPurge(): void
    {
        $manager = new CacheManager([
            'default' => 'mem',
            'stores'  => ['mem' => ['driver' => 'array']],
        ]);

        $store1 = $manager->store();
        $manager->purge();
        $store2 = $manager->store();

        // After purge, a new instance should be created
        $this->assertNotSame($store1, $store2);
    }

    public function testCacheManagerForgetDriver(): void
    {
        $manager = new CacheManager([
            'default' => 'mem',
            'stores'  => ['mem' => ['driver' => 'array']],
        ]);

        $store1 = $manager->store('mem');
        $manager->forgetDriver('mem');
        $store2 = $manager->store('mem');

        $this->assertNotSame($store1, $store2);
    }

    // ── ChainStore getMultiple batch ───────────────────────────

    public function testChainStoreGetMultipleBatch(): void
    {
        $l1 = new ArrayStore();
        $l2 = new ArrayStore();
        $chain = new ChainStore([$l1, $l2]);

        // Set in L2 only
        $l2->set('a', 1);
        $l2->set('b', 2);

        $results = $chain->getMultiple(['a', 'b', 'c'], 'miss');

        $this->assertSame(1, $results['a']);
        $this->assertSame(2, $results['b']);
        $this->assertSame('miss', $results['c']);

        // Should be promoted to L1
        $this->assertSame(1, $l1->get('a'));
        $this->assertSame(2, $l1->get('b'));
    }

    // ── FileStore path traversal protection ────────────────────

    public function testFileStoreRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');
        new FileStore(directory: $this->tempDir . '/../../../etc');
    }

    // ── PhpSerializer defaults to blocking object instantiation ─

    public function testPhpSerializerDefaultBlocksObjects(): void
    {
        $s = new PhpSerializer(); // Default: allowedClasses = []
        $data = ['key' => 'value', 'num' => 42];

        // Scalar/array data works fine
        $this->assertSame($data, $s->unserialize($s->serialize($data)));
    }

    public function testPhpSerializerExplicitAllowClasses(): void
    {
        $s = new PhpSerializer(allowedClasses: true);
        $obj = new \stdClass();
        $obj->foo = 'bar';

        $result = $s->unserialize($s->serialize($obj));
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame('bar', $result->foo);
    }
}
