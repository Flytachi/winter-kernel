<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Cache;

use Flytachi\Winter\Kernel\Cache\CacheDefinition;
use Flytachi\Winter\Kernel\Cache\CacheType;
use Flytachi\Winter\Kernel\Cache\Store\RedisCacheStore;
use Flytachi\Winter\Redis\RedisPool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Redis store against a real server — excluded from the default run.
 *
 * ```
 * docker run -d --rm --name wv-redis -p 56379:6379 redis:7-alpine
 * vendor/bin/phpunit --group integration tests/Cache/RedisCacheStoreTest.php
 * ```
 */
#[Group('integration')]
final class RedisCacheStoreTest extends TestCase
{
    /** @var list<RedisCacheStore> */
    private array $created = [];

    protected function setUp(): void
    {
        $socket = @fsockopen(
            (string) (getenv('WINTER_TEST_REDIS_HOST') ?: '127.0.0.1'),
            (int) (getenv('WINTER_TEST_REDIS_PORT') ?: 56379),
            $errno,
            $error,
            0.5,
        );
        if ($socket === false) {
            self::markTestSkipped('No Redis on the test port: ' . $error);
        }
        fclose($socket);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $store) {
            $store->clear();
        }
        RedisPool::reset();
    }

    private function store(string $name = 'it', ?int $ttl = null, ?string $version = null): RedisCacheStore
    {
        $store = new RedisCacheStore(new CacheDefinition(
            $name . '-' . getmypid(),
            CacheType::Redis,
            0,
            null,
            $ttl,
            TestRedisEndpoint::class,
            version: $version,
        ));
        $this->created[] = $store;

        return $store;
    }

    public function test_a_value_round_trips(): void
    {
        $store = $this->store();
        $store->put('user:1', serialize(['id' => 1]));

        self::assertSame(serialize(['id' => 1]), $store->get('user:1'));
        self::assertNull($store->get('user:2'));
    }

    public function test_the_lifetime_is_redis_own(): void
    {
        $store = $this->store(ttl: 300);
        $store->put('default', 'v');
        $store->put('short', 'v', 1);
        $store->put('forever', 'v', 0);

        $entries = array_column($store->entries(), 'expiresIn', 'key');
        self::assertContains($entries['default'], [299, 300]);
        self::assertNull($entries['forever']);

        usleep(1_200_000);
        self::assertNull($store->get('short'));
    }

    public function test_clearing_touches_only_this_cache(): void
    {
        $mine = $this->store('mine');
        $other = $this->store('other');
        $mine->put('a', 'v');
        $other->put('a', 'v');

        $mine->clear();

        self::assertSame(0, $mine->count());
        self::assertSame('v', $other->get('a'));
    }

    public function test_a_new_version_does_not_see_the_old_entries(): void
    {
        $this->store('versioned', version: 'v1')->put('token', 'old');

        self::assertNull($this->store('versioned', version: 'v2')->get('token'));
        self::assertSame('old', $this->store('versioned', version: 'v1')->get('token'));
    }

    public function test_entries_list_keys_without_the_prefix(): void
    {
        $store = $this->store();
        $store->put('user:1', '12345');

        self::assertSame([['key' => 'user:1', 'expiresIn' => null, 'bytes' => 5]], $store->entries());
        self::assertStringStartsWith('cache:it-', $store->location());
    }
}
