<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Cache\Attribute\CacheEvict;
use Flytachi\Winter\Kernel\Cache\Attribute\Cacheable;
use Flytachi\Winter\Kernel\Cache\Attribute\CachePut;
use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheInterceptor;
use Flytachi\Winter\Kernel\Cache\CacheManager;
use Flytachi\Winter\Kernel\Cache\CacheSupport;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Core\Proxy\ProxyFactory;
use Flytachi\Winter\Logger\LoggerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;

/**
 * What a cached method does at runtime, through a real generated proxy, against
 * both stores.
 */
final class CachedMethodTest extends TestCase
{
    private static string $directory = '';

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/wk_cache_' . getmypid() . '_' . bin2hex(random_bytes(4));
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$directory . '/*') ?: []);
        @rmdir(self::$directory);
    }

    protected function tearDown(): void
    {
        CacheManager::reset();
        CacheSupport::reset();
    }

    /** @return array<string, array{CacheType}> */
    public static function stores(): array
    {
        $stores = ['memory' => [CacheType::Memory]];
        if (extension_loaded('swoole')) {
            $stores['table'] = [CacheType::Table];
        }

        return $stores;
    }

    private static function service(CacheType $type, bool $enabled = true): CountingService
    {
        CacheManager::configure(
            ['items' => new CacheDefinition('items', $type, 100, 4096, null)],
            $enabled,
        );
        $proxy = new ProxyFactory(self::$directory, [new CacheInterceptor()], true)
            ->proxyFor(new ReflectionClass(CountingService::class));

        return new $proxy();
    }

    // ── Cacheable ──────────────────────────────────────────────────────────────

    #[DataProvider('stores')]
    public function test_a_hit_skips_the_body(CacheType $type): void
    {
        $service = self::service($type);

        self::assertSame('item 1', $service->find(1)->name);
        self::assertSame('item 1', $service->find(1)->name);
        self::assertSame(1, $service->calls);

        $service->find(2);
        self::assertSame(2, $service->calls, 'another key is another entry');
    }

    #[DataProvider('stores')]
    public function test_a_call_from_inside_the_class_is_cached_too(CacheType $type): void
    {
        $service = self::service($type);

        $service->findViaSelf(1);
        $service->findViaSelf(1);
        $service->find(1);

        self::assertSame(1, $service->calls, 'subclass proxying has no self-invocation hole');
    }

    #[DataProvider('stores')]
    public function test_every_hit_returns_its_own_copy(CacheType $type): void
    {
        $service = self::service($type);

        $first = $service->find(1);
        $first->name = 'changed by the caller';
        $second = $service->find(1);

        self::assertSame('item 1', $second->name);
        self::assertNotSame($first, $second);
    }

    #[DataProvider('stores')]
    public function test_null_is_cached_by_default(CacheType $type): void
    {
        $service = self::service($type);

        self::assertNull($service->find(0));
        self::assertNull($service->find(0));
        self::assertSame(1, $service->calls);
    }

    #[DataProvider('stores')]
    public function test_null_is_not_cached_when_asked(CacheType $type): void
    {
        $service = self::service($type);

        $service->findNoNull(0);
        $service->findNoNull(0);
        self::assertSame(2, $service->calls);
    }

    #[DataProvider('stores')]
    public function test_an_exception_is_not_cached(CacheType $type): void
    {
        $service = self::service($type);

        for ($i = 0; $i < 2; $i++) {
            try {
                $service->explode(1);
                self::fail('the body throws');
            } catch (\RuntimeException) {
            }
        }

        self::assertSame(2, $service->calls);
    }

    #[DataProvider('stores')]
    public function test_an_entry_expires(CacheType $type): void
    {
        $service = self::service($type);

        $service->shortLived(1);
        $service->shortLived(1);
        self::assertSame(1, $service->calls);

        usleep(1_100_000);
        $service->shortLived(1);
        self::assertSame(2, $service->calls);
    }

    #[DataProvider('stores')]
    public function test_a_value_that_cannot_be_serialized_is_returned_uncached(CacheType $type): void
    {
        $service = self::service($type);
        $logger = self::recordWarnings();

        try {
            self::assertInstanceOf(\Closure::class, $service->closure(1));
            self::assertInstanceOf(\Closure::class, $service->closure(1));
        } finally {
            self::stopRecording();
        }

        self::assertSame(2, $service->calls);
        self::assertCount(1, $logger->records, 'warned once, not once per call');
        self::assertStringContainsString("Cache 'items' skipped a write", $logger->records[0]['message']);
        self::assertStringContainsString('cannot be serialized', $logger->records[0]['message']);
    }

    // ── CachePut and CacheEvict ────────────────────────────────────────────────

    #[DataProvider('stores')]
    public function test_a_put_refreshes_the_entry_a_read_finds(CacheType $type): void
    {
        $service = self::service($type);

        $service->find(1);
        $service->save(new CachedItem(1, 'renamed'));

        self::assertSame('renamed', $service->find(1)->name);
        self::assertSame(2, $service->calls, 'find ran once, save once; the second find hit');
    }

    #[DataProvider('stores')]
    public function test_an_eviction_makes_the_next_read_compute(CacheType $type): void
    {
        $service = self::service($type);

        $service->find(1);
        $service->find(2);
        $service->delete(1);
        $service->find(1);
        $service->find(2);

        self::assertSame(4, $service->calls, 'find 1, find 2, delete, find 1 again; find 2 still hit');
    }

    #[DataProvider('stores')]
    public function test_all_entries_are_evicted(CacheType $type): void
    {
        $service = self::service($type);

        $service->find(1);
        $service->find(2);
        $service->wipe();
        $service->find(1);
        $service->find(2);

        self::assertSame(4, $service->calls);
    }

    #[DataProvider('stores')]
    public function test_a_failed_change_keeps_the_entry(CacheType $type): void
    {
        $service = self::service($type);
        $service->find(1);

        try {
            $service->failingDelete(1);
        } catch (\RuntimeException) {
        }
        $service->find(1);

        self::assertSame(1, $service->calls, 'the eviction after the body did not run');
    }

    #[DataProvider('stores')]
    public function test_an_eviction_before_the_body_happens_even_if_it_fails(CacheType $type): void
    {
        $service = self::service($type);
        $service->find(1);

        try {
            $service->failingDeleteBefore(1);
        } catch (\RuntimeException) {
        }
        $service->find(1);

        self::assertSame(2, $service->calls);
    }

    // ── Switched off ───────────────────────────────────────────────────────────

    #[DataProvider('stores')]
    public function test_cache_enabled_false_runs_every_body(CacheType $type): void
    {
        $service = self::service($type, enabled: false);

        $service->find(1);
        $service->find(1);
        $service->delete(1);

        self::assertSame(3, $service->calls);
    }

    public function test_an_unconfigured_cache_runs_every_body(): void
    {
        $service = self::service(CacheType::Memory);
        CacheManager::reset();

        $service->find(1);
        $service->find(1);

        self::assertSame(2, $service->calls);
    }

    // ── sync ───────────────────────────────────────────────────────────────────

    #[DataProvider('stores')]
    public function test_sync_computes_a_missing_entry_once_per_worker(CacheType $type): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('sync waits on coroutines.');
        }

        $service = self::service($type);
        $synced = [];
        $unsynced = [];

        \Swoole\Coroutine\run(static function () use ($service, &$synced, &$unsynced): void {
            $group = new \Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 10; $i++) {
                $group->add(2);
                \Swoole\Coroutine::create(static function () use ($service, $group, &$synced): void {
                    $synced[] = $service->slow(7);
                    $group->done();
                });
                \Swoole\Coroutine::create(static function () use ($service, $group, &$unsynced): void {
                    $unsynced[] = $service->unsynced(7);
                    $group->done();
                });
            }
            $group->wait();
        });

        self::assertSame(array_fill(0, 10, 7), $synced);
        self::assertSame(array_fill(0, 10, 7), $unsynced);
        self::assertSame(1, $service->slowCalls, 'ten concurrent misses, one computation');
        self::assertSame(10, $service->unsyncedCalls, 'without sync every miss computes');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static ?array $originalLoggers = null;

    /**
     * Seeds the factory's own cache with a recorder, the way ImportReportTest does: the
     * factory has no injection point, and its cache is keyed `channel:class`.
     */
    private static function recordWarnings(): CacheWarningRecorder
    {
        $recorder = new CacheWarningRecorder();
        $cache = new \ReflectionProperty(LoggerFactory::class, 'cache');
        $channel = new \ReflectionProperty(LoggerFactory::class, 'defaultChannel')->getValue();
        self::$originalLoggers = $cache->getValue();
        $cache->setValue(null, [$channel . ':' . CacheManager::class => $recorder] + self::$originalLoggers);

        return $recorder;
    }

    private static function stopRecording(): void
    {
        new \ReflectionProperty(LoggerFactory::class, 'cache')->setValue(null, self::$originalLoggers ?? []);
    }
}

// ── Fixtures ────────────────────────────────────────────────────────────────────

final class CachedItem
{
    public function __construct(public int $id, public string $name)
    {
    }
}

class CountingService
{
    public int $calls = 0;
    public int $slowCalls = 0;
    public int $unsyncedCalls = 0;

    #[Cacheable('items', key: '{id}')]
    public function find(int $id): ?CachedItem
    {
        $this->calls++;

        return $id === 0 ? null : new CachedItem($id, "item {$id}");
    }

    public function findViaSelf(int $id): ?CachedItem
    {
        return $this->find($id);
    }

    #[Cacheable('items', key: 'no-null:{id}', cacheNull: false)]
    public function findNoNull(int $id): ?CachedItem
    {
        $this->calls++;

        return null;
    }

    #[CachePut('items', key: '{result.id}')]
    public function save(CachedItem $item): CachedItem
    {
        $this->calls++;

        return $item;
    }

    #[CacheEvict('items', key: '{id}')]
    public function delete(int $id): void
    {
        $this->calls++;
    }

    #[CacheEvict('items', allEntries: true)]
    public function wipe(): void
    {
    }

    #[CacheEvict('items', key: '{id}')]
    public function failingDelete(int $id): void
    {
        throw new \RuntimeException('the change failed');
    }

    #[CacheEvict('items', key: '{id}', beforeInvocation: true)]
    public function failingDeleteBefore(int $id): void
    {
        throw new \RuntimeException('the change failed');
    }

    #[Cacheable('items', key: 'explode:{id}')]
    public function explode(int $id): int
    {
        $this->calls++;

        throw new \RuntimeException('the body failed');
    }

    #[Cacheable('items', key: 'short:{id}', ttl: 1)]
    public function shortLived(int $id): int
    {
        $this->calls++;

        return $id;
    }

    #[Cacheable('items', key: 'closure:{id}')]
    public function closure(int $id): mixed
    {
        $this->calls++;

        return static fn(): int => $id;
    }

    #[Cacheable('items', key: 'slow:{id}', sync: true)]
    public function slow(int $id): int
    {
        $this->slowCalls++;
        \Swoole\Coroutine::sleep(0.05);

        return $id;
    }

    #[Cacheable('items', key: 'unsynced:{id}')]
    public function unsynced(int $id): int
    {
        $this->unsyncedCalls++;
        \Swoole\Coroutine::sleep(0.05);

        return $id;
    }
}

final class CacheWarningRecorder extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }
}
