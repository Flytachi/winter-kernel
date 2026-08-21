<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Localization;

use Flytachi\Winter\Kernel\Core\RequestLocal;
use Flytachi\Winter\Kernel\Localization\Timezone;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The request's timezone, and why it cannot live in PHP's engine global.
 *
 * `date_default_timezone_set()` is process-wide. Under Swoole a worker serves many
 * requests at once in that one process, so a request that sets its client's zone and
 * then yields on I/O resumes to whatever a concurrent request wrote meanwhile — and
 * passes *that* to the database session for its own query. Reproduced live before this
 * class existed: Asia/Tashkent went in, Europe/London came back.
 *
 * Each test runs in its own process on purpose. Xdebug's function observers do not survive
 * coroutine stacks: once a child coroutine has suspended and resumed, the interpreter
 * segfaults in `xdebug_execute_user_code_end` at request shutdown — after the tests
 * themselves have passed, so the report says OK and the exit code says 139. Every
 * `xdebug.mode` does it, `coverage` included; the alternative is running the suite under
 * `XDEBUG_MODE=off`, which nobody remembers to do. Here the crash lands in a child whose
 * result is already out, and the run stays green wherever Xdebug happens to be loaded.
 */
#[RunTestsInSeparateProcesses]
final class TimezoneTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV['TIME_ZONE'] ?? null;
        RequestLocal::clear();
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === null) {
            unset($_ENV['TIME_ZONE']);
        } else {
            $_ENV['TIME_ZONE'] = $this->originalEnv;
        }
        RequestLocal::clear();
    }

    public function test_the_stored_zone_is_returned(): void
    {
        Timezone::set('Asia/Tashkent');

        self::assertSame('Asia/Tashkent', Timezone::current());
        self::assertTrue(Timezone::isSet());
    }

    public function test_without_a_stored_zone_the_environment_answers(): void
    {
        $_ENV['TIME_ZONE'] = 'Europe/Berlin';

        self::assertSame('Europe/Berlin', Timezone::current());
        self::assertFalse(Timezone::isSet(), 'a default is not the request\'s own zone');
    }

    public function test_with_no_environment_either_it_falls_back_to_utc(): void
    {
        unset($_ENV['TIME_ZONE']);

        self::assertSame('UTC', Timezone::current());
    }

    public function test_reset_returns_to_the_environment_default(): void
    {
        $_ENV['TIME_ZONE'] = 'Europe/Berlin';
        Timezone::set('Asia/Tashkent');

        Timezone::reset();

        self::assertSame('Europe/Berlin', Timezone::current());
    }

    /**
     * The failure that motivated the class. Request A must read its own zone after an
     * I/O wait, not the zone of a request that arrived while it waited.
     */
    public function test_a_concurrent_request_cannot_overwrite_the_zone(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Coroutine isolation needs Swoole.');
        }
        $seen = [];

        \Swoole\Coroutine\run(static function () use (&$seen): void {
            \Swoole\Coroutine::create(static function () use (&$seen): void {
                Timezone::set('Asia/Tashkent');
                \Swoole\Coroutine::sleep(0.05);
                $seen['A'] = Timezone::current();
            });
            \Swoole\Coroutine::create(static function () use (&$seen): void {
                \Swoole\Coroutine::sleep(0.01);
                Timezone::set('Europe/London');
                $seen['B'] = Timezone::current();
            });
        });

        self::assertSame('Asia/Tashkent', $seen['A']);
        self::assertSame('Europe/London', $seen['B']);
    }

    /**
     * PHP's own global is what this replaces — and the contrast is the point: the same
     * two coroutines mixed up their zones through `date_default_timezone_set()`.
     */
    public function test_the_php_global_is_still_shared_which_is_why_this_exists(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Coroutine isolation needs Swoole.');
        }
        $original = date_default_timezone_get();
        $seen = [];

        \Swoole\Coroutine\run(static function () use (&$seen): void {
            \Swoole\Coroutine::create(static function () use (&$seen): void {
                date_default_timezone_set('Asia/Tashkent');
                \Swoole\Coroutine::sleep(0.05);
                $seen['A'] = date_default_timezone_get();
            });
            \Swoole\Coroutine::create(static function (): void {
                \Swoole\Coroutine::sleep(0.01);
                date_default_timezone_set('Europe/London');
            });
        });

        date_default_timezone_set($original);

        self::assertSame(
            'Europe/London',
            $seen['A'],
            'if this ever fails, PHP made its default timezone coroutine-local and '
            . 'Timezone could delegate to it',
        );
    }
}
