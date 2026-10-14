<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheManager;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Cache\Store\MemoryStore;
use Flytachi\Winter\Kernel\Cache\Store\TableStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two stores on their own: eviction, expiry, and what a full or too small
 * table does.
 */
final class CacheStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        CacheManager::reset();
    }

    private static function memory(int $maxItems, ?int $ttl = null): MemoryStore
    {
        return new MemoryStore(new CacheDefinition('m', CacheType::Memory, $maxItems, null, $ttl));
    }

    private static function table(int $rows, int $valueSize = 64, ?int $ttl = null): TableStore
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('A table cache needs ext-swoole.');
        }

        return new TableStore(new CacheDefinition('t', CacheType::Table, $rows, $valueSize, $ttl));
    }

    // ── Memory ─────────────────────────────────────────────────────────────────

    public function test_memory_drops_the_entry_read_least_recently(): void
    {
        $store = self::memory(3);
        $store->put('a', '1');
        $store->put('b', '2');
        $store->put('c', '3');

        $store->get('a');      // a is now the most recently used
        $store->put('d', '4'); // b goes

        self::assertNull($store->get('b'));
        self::assertSame('1', $store->get('a'));
        self::assertSame('3', $store->get('c'));
        self::assertSame('4', $store->get('d'));
        self::assertSame(3, $store->count());
    }

    public function test_memory_rewriting_a_key_does_not_grow_it(): void
    {
        $store = self::memory(2);
        $store->put('a', '1');
        $store->put('a', '2');
        $store->put('b', '3');

        self::assertSame('2', $store->get('a'));
        self::assertSame(2, $store->count());
    }

    // ── Both ───────────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['memory' => ['memory'], 'table' => ['table']];
    }

    private static function store(string $kind, ?int $ttl = null): MemoryStore|TableStore
    {
        return $kind === 'memory' ? self::memory(100, $ttl) : self::table(100, 64, $ttl);
    }

    #[DataProvider('kinds')]
    public function test_the_cache_ttl_applies_when_the_call_gives_none(string $kind): void
    {
        $store = self::store($kind, ttl: 1);
        $store->put('default', 'x');
        $store->put('forever', 'x', 0);

        usleep(1_100_000);

        self::assertNull($store->get('default'));
        self::assertSame('x', $store->get('forever'), 'an explicit 0 never expires');
    }

    #[DataProvider('kinds')]
    public function test_evict_and_clear(string $kind): void
    {
        $store = self::store($kind);
        $store->put('a', '1');
        $store->put('b', '2');

        $store->evict('a');
        self::assertNull($store->get('a'));
        self::assertSame('2', $store->get('b'));

        $store->clear();
        self::assertNull($store->get('b'));
        self::assertSame(0, $store->count());
    }

    // ── Table ──────────────────────────────────────────────────────────────────

    public function test_a_table_holds_the_rows_it_was_declared_with(): void
    {
        // Swoole's own default holds as little as half of what it was asked for; the
        // collision share the store reserves is what makes `rows` a promise.
        foreach ([64, 1000, 1024, 5000] as $rows) {
            $store = self::table($rows);
            for ($i = 0; $i < $rows; $i++) {
                self::assertTrue($store->put("user:{$i}", 'v'), "row {$i} of {$rows}");
            }
            self::assertSame($rows, $store->count());
        }
    }

    public function test_a_value_larger_than_value_size_is_not_stored_and_the_old_one_goes(): void
    {
        $store = self::table(10, valueSize: 16);
        $store->put('k', 'short');

        self::assertFalse($store->put('k', str_repeat('x', 17)));
        self::assertNull($store->get('k'), 'a stale value must not survive a refused update');
    }

    public function test_a_full_table_refuses_the_write(): void
    {
        $store = self::table(64);
        $stored = 0;
        for ($i = 0; $i < 1000 && $store->put("k{$i}", 'v'); $i++) {
            $stored++;
        }

        self::assertGreaterThanOrEqual(64, $stored);
        self::assertLessThan(1000, $stored);
        // "Full" is per key: the refused key has no free slot left, another key may
        // still find one. The refused one keeps being refused, without an exception.
        self::assertFalse($store->put("k{$stored}", 'v'));
        self::assertNull($store->get("k{$stored}"));
    }

    public function test_a_full_table_makes_room_by_dropping_expired_rows(): void
    {
        $store = self::table(64);
        for ($i = 0; $i < 1000 && $store->put("k{$i}", 'v', 1); $i++) {
        }
        $refused = "k{$i}";
        self::assertFalse($store->put($refused, 'v'));

        usleep(1_100_000);

        self::assertTrue($store->put($refused, 'v'));
        self::assertSame('v', $store->get($refused));
    }

    public function test_a_table_created_before_a_fork_is_shared_with_the_child(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Needs ext-pcntl.');
        }

        $store = self::table(100);
        $store->put('from-parent', 'p');

        $pid = pcntl_fork();
        if ($pid === 0) {
            // The child sees the parent's row and writes one back.
            $store->put('from-child', $store->get('from-parent') === 'p' ? 'saw-parent' : 'blind');
            exit(0);
        }

        pcntl_waitpid($pid, $status);

        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame('saw-parent', $store->get('from-child'));
    }

    // ── Manager ────────────────────────────────────────────────────────────────

    public function test_allocate_reserves_nothing_while_caching_is_off(): void
    {
        CacheManager::configure(['t' => new CacheDefinition('t', CacheType::Table, 10, 64, null)], false);
        CacheManager::allocate();

        $stores = new \ReflectionProperty(CacheManager::class, 'stores')->getValue();
        self::assertSame([], $stores);
    }

    public function test_an_undeclared_store_is_an_error(): void
    {
        CacheManager::configure([], true);

        $this->expectExceptionMessage("Cache 'nope': it is not declared");
        CacheManager::store('nope');
    }

    public function test_the_reservation_estimate_follows_swoole_rounding(): void
    {
        $table = new CacheDefinition('t', CacheType::Table, 10_000, 4096, null);

        // 10 000 rows round up to 16 384, plus half again for collisions.
        self::assertSame((int) (16384 * 1.5 * (4096 + 120)), $table->reservedBytes());
        self::assertSame(0, new CacheDefinition('m', CacheType::Memory, 10_000, null, null)->reservedBytes());
    }
}
