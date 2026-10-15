<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheManager;
use Flytachi\Winter\Kernel\Cache\CacheSupport;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Cache\Store\CacheStore;
use Flytachi\Winter\Logger\LoggerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use ReflectionProperty;

/**
 * A cache that breaks must not break the method behind it.
 *
 * A store that cannot be reached turns into misses, and the worker stops asking it
 * for a while: an unreachable Redis waits out its connect timeout on every attempt,
 * so asking on every call would stall the application it was meant to speed up. A
 * value that no longer restores — written before a deploy changed its class — is a
 * miss as well.
 */
final class CacheResilienceTest extends TestCase
{
    private CacheWarningRecorder $log;
    private array $originalLoggers = [];

    protected function setUp(): void
    {
        $this->log = new CacheWarningRecorder();
        $cache = new ReflectionProperty(LoggerFactory::class, 'cache');
        $channel = new ReflectionProperty(LoggerFactory::class, 'defaultChannel')->getValue();
        $this->originalLoggers = $cache->getValue();
        $cache->setValue(null, [$channel . ':' . CacheManager::class => $this->log] + $this->originalLoggers);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(LoggerFactory::class, 'cache')->setValue(null, $this->originalLoggers);
        CacheManager::reset();
        CacheSupport::reset();
    }

    private static function withStore(CacheStore $store): void
    {
        CacheManager::configure(['c' => new CacheDefinition('c', CacheType::Redis, 0, null, null, 'Cfg')], true);
        $stores = new ReflectionProperty(CacheManager::class, 'stores');
        $stores->setValue(null, ['c' => $store]);
    }

    private static function cached(callable $body, string $key = 'k'): mixed
    {
        return CacheSupport::cacheable('c', $key, null, false, true, \Closure::fromCallable($body));
    }

    // ── An unreachable store ───────────────────────────────────────────────────

    public function test_an_unreachable_store_is_a_miss_and_the_method_still_answers(): void
    {
        self::withStore(new UnreachableStore());

        self::assertSame('computed', self::cached(static fn(): string => 'computed'));
        self::assertSame(LogLevel::WARNING, $this->log->records[0]['level']);
        self::assertStringContainsString("Cache 'c' is unavailable: read failed", $this->log->records[0]['message']);
        self::assertStringContainsString('Calls run without the cache', $this->log->records[0]['message']);
    }

    public function test_after_a_failure_the_store_is_left_alone_for_a_while(): void
    {
        $store = new UnreachableStore();
        self::withStore($store);

        for ($i = 0; $i < 5; $i++) {
            self::cached(static fn(): string => 'computed');
        }

        self::assertSame(1, $store->calls, 'one failed read; the write and four more calls skipped the store');
        self::assertCount(1, $this->log->records, 'and one warning, not five');
    }

    public function test_the_store_is_tried_again_once_the_pause_is_over(): void
    {
        $store = new UnreachableStore();
        self::withStore($store);
        self::cached(static fn(): string => 'computed');

        // Move the pause into the past instead of sleeping through it.
        $down = new ReflectionProperty(CacheSupport::class, 'down');
        $state = $down->getValue();
        $state['c'][0] = time() - 1;
        $down->setValue(null, $state);

        self::cached(static fn(): string => 'computed');

        self::assertSame(2, $store->calls);
    }

    public function test_a_failed_eviction_is_an_error_because_the_entry_stays_stale(): void
    {
        self::withStore(new UnreachableStore());

        CacheSupport::evict('c', 'k');

        self::assertSame(LogLevel::ERROR, $this->log->records[0]['level']);
        self::assertStringContainsString('evict failed', $this->log->records[0]['message']);
        self::assertStringContainsString('may still be served stale', $this->log->records[0]['message']);
    }

    public function test_an_eviction_during_the_pause_is_still_reported(): void
    {
        self::withStore(new UnreachableStore());
        self::cached(static fn(): string => 'computed');   // read fails, the pause starts

        CacheSupport::evict('c', 'k');

        $levels = array_column($this->log->records, 'level');
        self::assertContains(LogLevel::ERROR, $levels, 'an eviction that could not happen is never silent');
    }

    // ── A value that no longer restores ────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function unrestorable(): array
    {
        return [
            'class gone since the deploy' => ['O:29:"Gone\\RemovedSinceTheDeploy123":0:{}'],
            'corrupted bytes' => ['a:1:{i:0;s:5:"abc'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrestorable')]
    public function test_a_value_that_no_longer_restores_is_computed_again(string $stored): void
    {
        $store = new \Flytachi\Winter\Kernel\Cache\Store\MemoryStore(
            new CacheDefinition('c', CacheType::Memory, 10, null, null),
        );
        self::withStore($store);
        $store->put('k', $stored);

        self::assertSame('fresh', self::cached(static fn(): string => 'fresh'));
        self::assertSame(serialize('fresh'), $store->get('k'), 'the broken entry was replaced');
    }

    public function test_a_stored_false_is_a_hit_not_a_broken_value(): void
    {
        $store = new \Flytachi\Winter\Kernel\Cache\Store\MemoryStore(
            new CacheDefinition('c', CacheType::Memory, 10, null, null),
        );
        self::withStore($store);
        $calls = 0;
        $body = static function () use (&$calls): bool {
            $calls++;

            return false;
        };

        self::assertFalse(self::cached($body));
        self::assertFalse(self::cached($body));
        self::assertSame(1, $calls);
    }
}

/**
 * A store whose server is down: every call throws, as the winter-redis pool does when
 * it cannot connect.
 */
final class UnreachableStore implements CacheStore
{
    public int $calls = 0;

    public function get(string $key): ?string
    {
        return $this->fail();
    }

    public function put(string $key, string $value, ?int $ttl = null): bool
    {
        return $this->fail();
    }

    public function evict(string $key): void
    {
        $this->fail();
    }

    public function clear(): void
    {
        $this->fail();
    }

    public function count(): int
    {
        return $this->fail();
    }

    private function fail(): never
    {
        $this->calls++;

        throw new \RuntimeException('connection refused');
    }
}
