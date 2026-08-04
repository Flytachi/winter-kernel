<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Ppa\Pool;

use Flytachi\Winter\Cdo\Connection\CDO;
use Flytachi\Winter\Kernel\Core\RequestLocal;
use Flytachi\Winter\Kernel\Localization\Timezone;
use Flytachi\Winter\Kernel\Ppa\Pool\PpaConnectionPool;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The timezone a pooled connection carries into the database session.
 *
 * A pooled connection outlives the request that opened it and is handed to the next
 * user, so its session timezone has to be corrected on every use — leaving the previous
 * user's zone in place would serve a client in London dates from Tashkent. That is why
 * the call sits outside the borrow block in `coroutineDb()`.
 *
 * The zone must come from {@see Timezone} (coroutine-local), not from PHP's engine
 * global: the global is shared by every request in the worker, so a request that yielded
 * on I/O could resume after a concurrent one overwrote it and then hand *that* zone to
 * its own query.
 *
 * The redundant command is skipped per connection, which is a different thing from
 * hoisting it out: a connection arriving with the wrong zone is still corrected.
 */
final class PoolTimezoneTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV['TIME_ZONE'] ?? null;
        RequestLocal::clear();
        new ReflectionProperty(PpaConnectionPool::class, 'appliedTimezone')->setValue(null, null);
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === null) {
            unset($_ENV['TIME_ZONE']);
        } else {
            $_ENV['TIME_ZONE'] = $this->originalEnv;
        }
        RequestLocal::clear();
        new ReflectionProperty(PpaConnectionPool::class, 'appliedTimezone')->setValue(null, null);
    }

    /** Calls the private `syncTimezone($config, $cdo)` the way `coroutineDb()` does. */
    private function sync(object $config, RecordingCdo $cdo): void
    {
        new ReflectionMethod(PpaConnectionPool::class, 'syncTimezone')->invoke(null, $config, $cdo);
    }

    private function cdo(): RecordingCdo
    {
        return new ReflectionClass(RecordingCdo::class)->newInstanceWithoutConstructor();
    }

    public function test_the_session_gets_the_requests_zone(): void
    {
        Timezone::set('Asia/Tashkent');
        $cdo = $this->cdo();

        $this->sync(new \stdClass(), $cdo);

        self::assertSame(['Asia/Tashkent'], $cdo->applied);
    }

    public function test_without_a_request_zone_the_environment_default_is_used(): void
    {
        $_ENV['TIME_ZONE'] = 'Europe/Berlin';
        $cdo = $this->cdo();

        $this->sync(new \stdClass(), $cdo);

        self::assertSame(['Europe/Berlin'], $cdo->applied);
    }

    /** The engine global is not the source — that is the whole point of the change. */
    public function test_the_php_global_is_ignored(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/New_York');   // another request's leftover
        $_ENV['TIME_ZONE'] = 'UTC';
        $cdo = $this->cdo();

        try {
            $this->sync(new \stdClass(), $cdo);
        } finally {
            date_default_timezone_set($original);
        }

        self::assertSame(['UTC'], $cdo->applied, 'the global must not reach the session');
    }

    public function test_the_same_zone_is_not_sent_twice_to_one_connection(): void
    {
        Timezone::set('Asia/Tashkent');
        $config = new \stdClass();
        $cdo    = $this->cdo();

        $this->sync($config, $cdo);
        $this->sync($config, $cdo);
        $this->sync($config, $cdo);

        self::assertSame(['Asia/Tashkent'], $cdo->applied, 'one command, not three');
    }

    /**
     * The case the memo must not swallow: the connection comes back from the pool while
     * a different user holds it.
     */
    public function test_a_changed_zone_is_applied_again(): void
    {
        $config = new \stdClass();
        $cdo    = $this->cdo();

        Timezone::set('Asia/Tashkent');
        $this->sync($config, $cdo);
        Timezone::set('Europe/London');
        $this->sync($config, $cdo);

        self::assertSame(['Asia/Tashkent', 'Europe/London'], $cdo->applied);
    }

    /** The memo is per connection: one connection's state says nothing about another's. */
    public function test_each_connection_is_tracked_separately(): void
    {
        Timezone::set('Asia/Tashkent');
        $first  = $this->cdo();
        $second = $this->cdo();

        $this->sync($configA = new \stdClass(), $first);
        $this->sync($configB = new \stdClass(), $second);

        self::assertSame(['Asia/Tashkent'], $first->applied);
        self::assertSame(['Asia/Tashkent'], $second->applied, 'a fresh connection needs its own SET');
        self::assertNotSame($configA, $configB);
    }

    public function test_a_driverless_connection_is_left_alone(): void
    {
        Timezone::set('Asia/Tashkent');
        $cdo = $this->cdo();
        $cdo->driver = '';

        $this->sync(new \stdClass(), $cdo);

        self::assertSame([], $cdo->applied);
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/**
 * A CDO that records the timezones asked of it instead of talking to a server.
 * Built via `newInstanceWithoutConstructor()`, so no connection is ever opened.
 */
final class RecordingCdo extends CDO
{
    /** @var list<string> */
    public array $applied = [];
    public string $driver = 'pgsql';

    public function getAttribute(int $attribute): mixed
    {
        return $this->driver;
    }

    public function applyDatabaseTimezone(mixed $driver, string $tz): void
    {
        $this->applied[] = $tz;
    }
}
