<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Core;

use Flytachi\Winter\Kernel\Core\RequestLocal;
use PHPUnit\Framework\TestCase;

/**
 * The storage that keeps one request's values out of another's.
 *
 * The whole point is the coroutine case: a Swoole worker serves many requests at once
 * inside one process, so a value kept in a static property is shared by all of them —
 * and a request that yields on I/O can resume to find a concurrent request's value in
 * its place. Off the coroutine path there is one request per process, so a static is
 * the correct equivalent and the caller should not have to know the difference.
 */
final class RequestLocalTest extends TestCase
{
    protected function setUp(): void
    {
        RequestLocal::clear();
    }

    protected function tearDown(): void
    {
        RequestLocal::clear();
    }

    // ── Outside a coroutine: one unit of work per process ──────────────────────

    public function test_a_value_survives_within_the_same_unit_of_work(): void
    {
        RequestLocal::set('user', 'alice');

        self::assertSame('alice', RequestLocal::get('user'));
        self::assertTrue(RequestLocal::has('user'));
    }

    public function test_a_missing_key_returns_the_default(): void
    {
        self::assertNull(RequestLocal::get('nothing'));
        self::assertSame('fallback', RequestLocal::get('nothing', 'fallback'));
        self::assertFalse(RequestLocal::has('nothing'));
    }

    public function test_forget_drops_one_value_and_leaves_the_rest(): void
    {
        RequestLocal::set('a', 1);
        RequestLocal::set('b', 2);

        RequestLocal::forget('a');

        self::assertNull(RequestLocal::get('a'));
        self::assertSame(2, RequestLocal::get('b'), 'forget is not clear');
    }

    public function test_clear_resets_the_unit_of_work(): void
    {
        RequestLocal::set('a', 1);
        RequestLocal::set('b', 2);

        RequestLocal::clear();

        self::assertFalse(RequestLocal::has('a'));
        self::assertFalse(RequestLocal::has('b'));
    }

    // ── Inside coroutines: the reason this class exists ────────────────────────

    /**
     * The failure this replaces: request A stores its value, yields on I/O, request B
     * stores its own, and A resumes reading B's. With a process-wide store that is what
     * happens — measured on `date_default_timezone_set()` before this class existed.
     */
    public function test_concurrent_requests_do_not_see_each_others_values(): void
    {
        $this->requireSwoole();
        $seen = [];

        \Swoole\Coroutine\run(static function () use (&$seen): void {
            \Swoole\Coroutine::create(static function () use (&$seen): void {
                RequestLocal::set('tz', 'Asia/Tashkent');
                \Swoole\Coroutine::sleep(0.05);              // wait on I/O
                $seen['A'] = RequestLocal::get('tz');        // resume
            });
            \Swoole\Coroutine::create(static function () use (&$seen): void {
                \Swoole\Coroutine::sleep(0.01);              // lands while A waits
                RequestLocal::set('tz', 'Europe/London');
                $seen['B'] = RequestLocal::get('tz');
            });
        });

        self::assertSame('Asia/Tashkent', $seen['A'], 'request A must not read B\'s value');
        self::assertSame('Europe/London', $seen['B']);
    }

    public function test_a_coroutine_starts_without_the_previous_ones_values(): void
    {
        $this->requireSwoole();
        $second = 'unset';

        \Swoole\Coroutine\run(static function () use (&$second): void {
            \Swoole\Coroutine::create(static function (): void {
                RequestLocal::set('leftover', 'first request');
            });
            \Swoole\Coroutine::create(static function () use (&$second): void {
                $second = RequestLocal::get('leftover', 'clean');
            });
        });

        self::assertSame('clean', $second, 'a finished request leaves nothing behind');
    }

    /** The static fallback must not leak into the coroutine path, or vice versa. */
    public function test_the_two_runtimes_keep_separate_stores(): void
    {
        $this->requireSwoole();
        RequestLocal::set('where', 'outside');
        $inside = 'unset';

        \Swoole\Coroutine\run(static function () use (&$inside): void {
            $inside = RequestLocal::get('where', 'nothing here');
        });

        self::assertSame('nothing here', $inside);
        self::assertSame('outside', RequestLocal::get('where'), 'the outer store is untouched');
    }

    private function requireSwoole(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Coroutine isolation needs Swoole.');
        }
    }
}
