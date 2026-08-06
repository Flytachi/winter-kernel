<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Config\Profile;
use Flytachi\Winter\Kernel\App\Config\ServerSettings;
use Flytachi\Winter\Kernel\App\Config\WorkerMemory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;

/**
 * The per-worker memory ceiling, and the arithmetic nobody performs by hand.
 *
 * A Swoole worker serves many requests at once out of one PHP heap, so `memory_limit`
 * bounds their sum. When it is reached the worker dies and takes every request it was
 * holding — measured live: 1 193 coroutines discarded at once, and with a single worker
 * the container went down with it.
 *
 * The two numbers that decide whether a box holds — the limit, and how many workers run
 * — are configured in different places, so the product goes unnoticed until something
 * dies under load. These tests cover saying it out loud, and the one case where the
 * configuration is worse than useless: `-1`.
 */
final class WorkerMemoryTest extends TestCase
{
    private function check(?string $limit, int $workers, ?int $boxBytes = null): RecordingLogger
    {
        $logger = new RecordingLogger();
        WorkerMemory::check($limit, $workers, $logger, $boxBytes);

        return $logger;
    }

    private const int GIB = 1024 ** 3;

    private function toBytes(string $value): int
    {
        return new ReflectionMethod(WorkerMemory::class, 'toBytes')->invoke(null, $value);
    }

    // ── Parsing PHP's shorthand ────────────────────────────────────────────────

    public function test_php_memory_shorthand_is_understood(): void
    {
        self::assertSame(256 * 1024 ** 2, $this->toBytes('256M'));
        self::assertSame(1024 ** 3, $this->toBytes('1G'));
        self::assertSame(512 * 1024, $this->toBytes('512K'));
        self::assertSame(1000, $this->toBytes('1000'), 'a bare number is bytes');
        self::assertSame(256 * 1024 ** 2, $this->toBytes(' 256m '), 'case and spacing are PHP-tolerant');
    }

    public function test_unlimited_is_distinguished_from_unparseable(): void
    {
        self::assertSame(-1, $this->toBytes('-1'), 'unlimited');
        self::assertSame(0, $this->toBytes('nonsense'), 'unparseable — PHP reports it better than we can');
        self::assertSame(0, $this->toBytes(''));
    }

    // ── The one configuration that is worse than a limit ───────────────────────

    /**
     * Unlimited does not remove the ceiling, it moves it into the kernel: PHP never
     * stops the process, so the OOM killer does — with no shutdown functions, no log
     * entry, and the whole container when the server is PID 1.
     */
    public function test_unlimited_is_warned_about(): void
    {
        $logger = $this->check('-1', 4);

        self::assertCount(1, $logger->warnings());
        self::assertStringContainsString('OOM killer', $logger->warnings()[0]);
    }

    public function test_a_real_limit_is_not_warned_about(): void
    {
        self::assertSame([], $this->check('64M', 1)->warnings());
    }

    public function test_an_unparseable_limit_is_left_to_php(): void
    {
        self::assertSame([], $this->check('not-a-size', 4)->warnings(), 'no second opinion');
    }

    // ── The arithmetic ─────────────────────────────────────────────────────────

    /** The case this exists for: four workers at 512M cannot live in a 1 GiB box. */
    public function test_a_fleet_larger_than_the_box_is_reported(): void
    {
        $warnings = $this->check('512M', 4, boxBytes: self::GIB)->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('4 worker(s) × 512 MiB', $warnings[0]);
        self::assertStringContainsString('2 GiB', $warnings[0], 'the product is spelled out');
        self::assertStringContainsString('1 GiB', $warnings[0], 'against what the box has');
    }

    public function test_a_fleet_that_fits_is_silent(): void
    {
        self::assertSame([], $this->check('128M', 4, boxBytes: 4 * self::GIB)->warnings());
    }

    /** The same limit is fine alone and over-committed multiplied — that is the point. */
    public function test_the_worker_count_is_what_turns_a_fit_into_an_over_commit(): void
    {
        self::assertSame([], $this->check('512M', 1, boxBytes: self::GIB)->warnings());
        self::assertCount(1, $this->check('512M', 8, boxBytes: self::GIB)->warnings());
    }

    /**
     * Worst-case estimate, so it warns and never throws: every worker peaking together
     * is rare, and over-committing is a legitimate choice. Refusing would break working
     * deployments over a guess — unlike the DI scope check, where the condition is
     * certainly wrong.
     */
    public function test_over_commit_never_throws(): void
    {
        $this->check('4G', 128, boxBytes: 256 * 1024 ** 2);

        $this->addToAssertionCount(1); // reaching this line is the assertion
    }

    public function test_nothing_is_reported_when_the_box_has_no_limit(): void
    {
        // An unlimited (or unreadable) ceiling cannot be judged, and silence is the
        // honest answer — no cgroup limit is set on the test machine either.
        self::assertSame([], $this->check('4G', 128)->warnings());
    }

    // ── Applying it ────────────────────────────────────────────────────────────

    public function test_apply_sets_the_ini_for_this_process(): void
    {
        $original = ini_get('memory_limit');

        try {
            WorkerMemory::apply('321M');
            self::assertSame('321M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * Configure nothing and the framework must not touch the ini at all.
     *
     * Reaching `ini_set()` with an empty value does not change the limit — it fails and
     * leaves it alone — but it does raise `Failed to set memory limit to 0 bytes`, and a
     * PHP warning on every worker start reads like a bug. So what is asserted is the
     * silence, not just the value.
     */
    public function test_apply_without_a_limit_touches_nothing_and_says_nothing(): void
    {
        $original = ini_get('memory_limit');
        $diagnostics = [];
        set_error_handler(static function (int $no, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;
            return true;
        });

        try {
            WorkerMemory::apply(null);
            WorkerMemory::apply('');
        } finally {
            restore_error_handler();
        }

        self::assertSame($original, ini_get('memory_limit'));
        self::assertSame([], $diagnostics, 'the ini was never touched');
    }

    // ── Handing idle memory back ───────────────────────────────────────────────

    /**
     * Leaves the allocator holding roughly 30 MB it no longer needs — the shape of a
     * request that built a large result, at a size the 128M test limit tolerates. The
     * mechanism does not care about the magnitude; the live case was 258 MB.
     */
    private function buildAndDropALargeResult(): void
    {
        $many = [];
        for ($i = 0; $i < 60_000; $i++) {
            $many[] = (object) ['a' => "value {$i}", 'b' => $i];
        }
        unset($many);
    }

    /**
     * PHP frees memory to its own allocator, not to the kernel: many small objects live
     * in chunks that are kept for reuse, so a worker that once built a large result goes
     * on holding that memory for the rest of its life. Measured on a live worker — a
     * request building 100 000 entities left `top` reading 114 MB against 6 MB in use.
     */
    public function test_a_large_reserve_is_handed_back(): void
    {
        $this->buildAndDropALargeResult();

        $reserveBefore = memory_get_usage(true) - memory_get_usage();
        self::assertGreaterThan(
            8 * 1024 ** 2,
            $reserveBefore,
            'the fixture must actually leave a reserve, or the test proves nothing',
        );

        $freed = WorkerMemory::trimIfIdle(8 * 1024 ** 2);

        self::assertGreaterThan(0, $freed);
        self::assertLessThan(
            $reserveBefore,
            memory_get_usage(true) - memory_get_usage(),
            'the reserve must actually shrink',
        );
    }

    /** An ordinary worker carries a few megabytes of reserve and must be left alone. */
    public function test_a_small_reserve_is_left_alone(): void
    {
        WorkerMemory::trimIfIdle(1024);          // clear whatever this process holds

        self::assertSame(0, WorkerMemory::trimIfIdle(512 * 1024 ** 2), 'nothing near half a gig');
    }

    /**
     * The threshold has to be the deciding factor, not a formality: with a reserve worth
     * releasing but a threshold above it, nothing may happen. Otherwise every request
     * would pay the release — 80 ms when there is a lot to give back.
     */
    public function test_a_reserve_below_the_threshold_is_kept(): void
    {
        $this->buildAndDropALargeResult();

        $reserve = memory_get_usage(true) - memory_get_usage();
        self::assertGreaterThan(8 * 1024 ** 2, $reserve, 'there is something to release');

        self::assertSame(
            0,
            WorkerMemory::trimIfIdle(512 * 1024 ** 2),
            '…but the threshold says it is not worth it',
        );

        WorkerMemory::trimIfIdle(8 * 1024 ** 2);   // tidy up for the tests that follow
    }

    /**
     * Why the decision is made on the reserve and not on the process peak.
     *
     * A worker in the middle of a large request has a **high peak and almost no
     * reserve** — the memory is in use, none of it is idle. Once the request ends the
     * reserve is exactly what stayed behind. The peak, by contrast, never decreases: it
     * says "something large happened here once" for the rest of the worker's life and
     * cannot tell whether anything is being held *now*.
     *
     * Note what this test does and does not prove. The two triggers differ in **cost**,
     * not in outcome: with memory in use, releasing would find nothing to release either
     * way, so both return 0. What is provable — and what this asserts — is that the
     * reserve tracks what is idle while the peak does not.
     */
    public function test_the_reserve_falls_again_while_the_peak_never_does(): void
    {
        WorkerMemory::trimIfIdle(1024);

        $held = [];
        for ($i = 0; $i < 60_000; $i++) {
            $held[] = (object) ['a' => "value {$i}", 'b' => $i];
        }

        self::assertGreaterThan(8 * 1024 ** 2, memory_get_peak_usage(), 'the peak is high…');
        self::assertLessThan(8 * 1024 ** 2, WorkerMemory::idleReserve(), '…while nothing is idle');
        self::assertSame(0, WorkerMemory::trimIfIdle(8 * 1024 ** 2), 'a busy worker is left alone');

        unset($held);

        self::assertGreaterThan(
            8 * 1024 ** 2,
            WorkerMemory::idleReserve(),
            'the request ended and its memory is now idle — this is what the peak cannot say',
        );

        WorkerMemory::trimIfIdle(8 * 1024 ** 2);

        self::assertLessThan(
            8 * 1024 ** 2,
            WorkerMemory::idleReserve(),
            'and the reserve falls again, which is why one release is enough',
        );
        self::assertGreaterThan(
            8 * 1024 ** 2,
            memory_get_peak_usage(),
            'while the peak still reads high, and would go on triggering forever',
        );
    }

    /**
     * Releasing closes the gap, so the next check finds nothing to do. That is what keeps
     * a busy worker from paying the cost on every request — unlike the process peak,
     * which never decreases and would trip forever after one heavy request.
     */
    public function test_the_trigger_is_self_correcting(): void
    {
        $this->buildAndDropALargeResult();

        self::assertGreaterThan(0, WorkerMemory::trimIfIdle(8 * 1024 ** 2), 'first call releases');
        self::assertSame(0, WorkerMemory::trimIfIdle(8 * 1024 ** 2), 'second finds nothing');
    }

    public function test_a_zero_threshold_disables_it(): void
    {
        $this->buildAndDropALargeResult();

        self::assertSame(0, WorkerMemory::trimIfIdle(0), 'opted out, whatever is held');

        WorkerMemory::trimIfIdle(8 * 1024 ** 2);   // tidy up for the tests that follow
    }

    // ── Wiring through ServerSettings ──────────────────────────────────────────

    public function test_the_setting_is_absent_until_asked_for(): void
    {
        self::assertNull(ServerSettings::fromEnv()->getMemoryLimit());
    }

    public function test_the_setting_round_trips(): void
    {
        self::assertSame('256M', ServerSettings::fromEnv()->memoryLimit('256M')->getMemoryLimit());
    }

    public function test_the_env_shorthand_seeds_it(): void
    {
        $original = $_ENV['SERVER_MEMORY_LIMIT'] ?? null;
        $_ENV['SERVER_MEMORY_LIMIT'] = '512M';

        try {
            self::assertSame('512M', ServerSettings::fromEnv()->getMemoryLimit());
        } finally {
            if ($original === null) {
                unset($_ENV['SERVER_MEMORY_LIMIT']);
            } else {
                $_ENV['SERVER_MEMORY_LIMIT'] = $original;
            }
        }
    }

    /**
     * It is a PHP ini, not a Swoole option — and Swoole answers an option it does not
     * know with `unsupported option` on every start, as it does for
     * `max_request_execution_time`.
     */
    public function test_it_never_reaches_the_swoole_options(): void
    {
        $options = ServerSettings::fromEnv()
            ->workers(4)
            ->memoryLimit('256M')
            ->memoryTrimThreshold('64M')
            ->toArray();

        self::assertArrayHasKey('worker_num', $options);
        self::assertArrayNotHasKey('memory_limit', $options);
        self::assertArrayNotHasKey('memoryLimit', $options);
        self::assertArrayNotHasKey('memoryTrimThreshold', $options);
    }

    /**
     * Worker recycling is on by default, and has to be: Swoole leaks 56 bytes every time
     * a coroutine suspends — measured to the byte — and an HTTP request always suspends.
     * At 2 000 req/s that is ~385 MB an hour, so a worker that never recycles dies of it.
     */
    public function test_worker_recycling_is_configured_by_default(): void
    {
        $options = self::settings('256M')->toArray();

        self::assertSame(157_903, $options['max_request'], 'a worker must not live forever');
        self::assertSame(15_790, $options['max_request_grace'], 'and they must not all recycle at once');
    }

    /**
     * Swoole **adds** the grace to the limit — verified live: at `max_request = 20,
     * grace = 15` worker instances served 30, 27, 34 and 26 requests, while at `grace = 0`
     * every one served exactly 20. So the default spread is 100 000 – 110 000.
     */
    public function test_recycling_can_be_overridden(): void
    {
        $options = ServerSettings::fromEnv()->maxRequest(500)->maxRequestGrace(50)->toArray();

        self::assertSame(500, $options['max_request']);
        self::assertSame(50, $options['max_request_grace']);
    }

    public function test_the_env_shorthand_overrides_the_recycling_default(): void
    {
        $original = $_ENV['SERVER_MAX_REQUEST'] ?? null;
        $_ENV['SERVER_MAX_REQUEST'] = '250000';

        try {
            self::assertSame(250_000, ServerSettings::fromEnv()->toArray()['max_request']);
        } finally {
            if ($original === null) {
                unset($_ENV['SERVER_MAX_REQUEST']);
            } else {
                $_ENV['SERVER_MAX_REQUEST'] = $original;
            }
        }
    }

    /**
     * Swoole's own limit is 2 MB — measured, 2 000 KB passes and 2 048 KB comes back 413
     * — which is tight for anything accepting a document, and invisible when it bites.
     * 8 MB matches PHP's `post_max_size`, the number a PHP developer expects.
     */
    public function test_the_request_size_limit_is_raised_from_swooles_2mb(): void
    {
        self::assertSame(
            8 * 1024 ** 2,
            ServerSettings::fromEnv()->toArray()['package_max_length'],
        );
        self::assertSame(
            32 * 1024 ** 2,
            ServerSettings::fromEnv()->maxRequestSize(32 * 1024 ** 2)->toArray()['package_max_length'],
        );
    }

    /**
     * The idle timeout stays off, because Swoole applies it to requests that are still
     * running: it measures the time since the client last *sent* something, and a client
     * waiting for a slow response sends nothing. Measured — with the timeout at 2 seconds
     * a 5-second request was cut at 2.07 s with no response and no log line; at 8 seconds
     * the same request returned normally.
     *
     * A default here would therefore be a silent ceiling on request duration, overruling
     * #[Timeout] on the routes that legitimately take longer. What it would buy is small:
     * the connection table costs about 160 KB between 1 024 and 1 000 000 connections.
     */
    public function test_no_idle_timeout_is_imposed_on_running_requests(): void
    {
        self::assertArrayNotHasKey('heartbeat_idle_time', ServerSettings::fromEnv()->toArray());
        self::assertSame(
            600,
            ServerSettings::fromEnv()->idleConnectionTimeout(600)->toArray()['heartbeat_idle_time'],
        );
    }

    /**
     * Connections are not requests. A keep-alive connection serves many in turn, and idle
     * ones hold none at all — measured: `max_connection = 2` did not slow six concurrent
     * requests, while `worker_max_concurrency = 2` doubled their time by queueing. So this
     * bounds file descriptors, not work, and Swoole's own 100 000 is left alone: lowering
     * it only refuses clients earlier, and memory runs out first.
     */
    public function test_the_connection_ceiling_is_derived_rather_than_left_to_swoole(): void
    {
        // Swoole's own 100 000 is no limit at all: at the measured 68 KB apiece it would
        // take 6.8 GB to reach, and the worker dies at ~1 900 with the default 128M.
        self::assertSame(1792, self::settings('256M')->toArray()['max_connection']);
        self::assertSame(
            5000,
            self::settings('256M')->maxConnections(5000)->toArray()['max_connection'],
        );
    }

    /**
     * Every in-flight request is budgeted at the measured floor (78 KB) plus what the
     * profile grants it, plus one idle connection (68 KB) for the keep-alive client that
     * is connected and not currently asking.
     */
    public function test_each_profile_derives_its_own_concurrency(): void
    {
        $expected = [
            // profile,             256M,  128M
            [Profile::Stable,        373,   174],
            [Profile::Balance,       896,   418],
            [Profile::Performance,  1170,   546],
        ];

        foreach ($expected as [$profile, $at256, $at128]) {
            self::assertSame(
                $at256,
                self::settings('256M')->profile($profile)->getMaxConcurrency(),
                $profile->value . ' at 256M',
            );
            self::assertSame(
                $at128,
                self::settings('128M')->profile($profile)->getMaxConcurrency(),
                $profile->value . ' at 128M',
            );
        }
    }

    /** Connections are the working ones plus an idle one apiece. */
    public function test_connections_leave_room_for_idle_keep_alive_clients(): void
    {
        $settings = self::settings('256M')->profile(Profile::Balance);

        self::assertSame($settings->getMaxConcurrency() * 2, $settings->getMaxConnections());
    }

    /**
     * A socket is a file descriptor, so `ulimit -n` bounds connections independently of
     * memory — and is the tighter ceiling on a stingy host. Asked for more than it allows,
     * Swoole prints `max_connection is exceed the maximum value, it's reset to N` and uses
     * N, so a derived number above it would be a number the framework reports and Swoole
     * ignores.
     */
    public function test_derived_connections_never_exceed_the_descriptor_limit(): void
    {
        $available = 240 * 1024 ** 2;

        self::assertSame(1792, Profile::Balance->connections($available, PHP_INT_MAX));
        self::assertSame(1024, Profile::Balance->connections($available, 1024), 'clamped by descriptors');
        self::assertSame(1, Profile::Balance->connections($available, 0), 'never zero, which means "no cap"');
    }

    /** A request in flight holds a socket, so the descriptor ceiling binds concurrency too. */
    public function test_concurrency_never_exceeds_the_connections_that_carry_it(): void
    {
        $settings = self::settings('256M')->maxConnections(100);

        self::assertSame(100, $settings->getMaxConnections());
        self::assertSame(100, $settings->getMaxConcurrency(), 'cannot work more requests than sockets');
    }

    /**
     * An explicit value above the limit is left alone. Swoole's warning is then the only
     * thing telling the operator to raise `ulimit -n`, and swallowing it would leave them
     * wondering why a number they set is not the number in force.
     */
    public function test_an_explicit_connection_ceiling_is_left_for_swoole_to_complain_about(): void
    {
        self::assertSame(999_999, self::settings('256M')->maxConnections(999_999)->getMaxConnections());
    }

    /** Balance is what an application that says nothing gets. */
    public function test_balance_is_the_default_profile(): void
    {
        self::assertSame(Profile::Balance, ServerSettings::fromEnv()->getProfile());
        self::assertSame(
            self::settings('256M')->profile(Profile::Balance)->getMaxConcurrency(),
            self::settings('256M')->getMaxConcurrency(),
        );
    }

    /** The bench profile caps nothing, so it contributes no key and Swoole's own stands. */
    public function test_stress_removes_the_caps_entirely(): void
    {
        $options = self::settings('256M')->profile(Profile::Stress)->toArray();

        foreach (['worker_max_concurrency', 'max_connection', 'max_request', 'max_request_grace'] as $key) {
            self::assertArrayNotHasKey($key, $options, "{$key} must be left to Swoole under stress");
        }
        self::assertSame(0, self::settings('256M')->profile(Profile::Stress)->getMemoryTrimThreshold());
    }

    /**
     * Resolved on read, so the order of calls in a WebConfigurer cannot matter — deriving
     * in fromEnv() would freeze the ini's value before ->memoryLimit() is reached.
     */
    public function test_raising_the_memory_limit_raises_everything_derived_from_it(): void
    {
        $settings = self::settings('256M');
        $before   = $settings->getMaxConcurrency();

        $settings->memoryLimit('512M');

        self::assertSame(1853, $settings->getMaxConcurrency());
        self::assertGreaterThan($before, $settings->getMaxConcurrency());
        self::assertSame(1853, $settings->toArray()['worker_max_concurrency']);
        self::assertSame(3706, $settings->toArray()['max_connection']);
    }

    /** An explicit value wins over the profile, whichever way it was given. */
    public function test_a_configured_value_is_not_second_guessed(): void
    {
        self::assertSame(
            10000,
            self::settings('128M')->maxConcurrency(10000)->getMaxConcurrency(),
        );

        $original = $_ENV['SERVER_MAX_CONCURRENCY'] ?? null;
        $_ENV['SERVER_MAX_CONCURRENCY'] = '7777';
        try {
            self::assertSame(7777, ServerSettings::fromEnv()->getMaxConcurrency());
        } finally {
            if ($original === null) {
                unset($_ENV['SERVER_MAX_CONCURRENCY']);
            } else {
                $_ENV['SERVER_MAX_CONCURRENCY'] = $original;
            }
        }
    }

    /** The profile can be chosen by an operator without touching code. */
    public function test_the_profile_can_come_from_the_environment(): void
    {
        $original = $_ENV['SERVER_PROFILE'] ?? null;

        try {
            $_ENV['SERVER_PROFILE'] = 'performance';
            self::assertSame(Profile::Performance, ServerSettings::fromEnv()->getProfile());

            $_ENV['SERVER_PROFILE'] = '  STRESS ';
            self::assertSame(Profile::Stress, ServerSettings::fromEnv()->getProfile());

            $_ENV['SERVER_PROFILE'] = 'nonsense';
            self::assertSame(Profile::Balance, ServerSettings::fromEnv()->getProfile(), 'an unknown name is not fatal');
        } finally {
            if ($original === null) {
                unset($_ENV['SERVER_PROFILE']);
            } else {
                $_ENV['SERVER_PROFILE'] = $original;
            }
        }
    }

    /**
     * With no limit to derive from there is still a ceiling. And it never derives zero,
     * which Swoole stores as given and reads as "no limit" — verified — so a tiny budget
     * would become an unlimited one.
     */
    public function test_an_unreadable_memory_limit_still_yields_a_ceiling(): void
    {
        self::assertSame(418, self::settings('-1')->getMaxConcurrency(), 'falls back to a 128M budget');
        self::assertSame(418, self::settings('lots')->getMaxConcurrency());
        self::assertSame(1, self::settings('1K')->getMaxConcurrency(), 'never zero, which would mean no limit');
    }

    /** The threshold scales with the limit: what counts as "unused" depends on how much there is. */
    public function test_the_trim_threshold_follows_the_profile(): void
    {
        $of = static fn(Profile $p): int => self::settings('256M')->profile($p)->getMemoryTrimThreshold();

        self::assertSame(32 * 1024 ** 2, self::settings('256M')->getMemoryTrimThreshold());
        self::assertSame(16 * 1024 ** 2, $of(Profile::Stable));
        self::assertSame(64 * 1024 ** 2, $of(Profile::Performance));
    }

    public function test_the_trim_threshold_is_configurable(): void
    {
        self::assertSame(64 * 1024 ** 2, self::settings('256M')->memoryTrimThreshold('64M')->getMemoryTrimThreshold());

        $original = $_ENV['SERVER_MEMORY_TRIM'] ?? null;
        $_ENV['SERVER_MEMORY_TRIM'] = '128M';

        try {
            self::assertSame(128 * 1024 ** 2, ServerSettings::fromEnv()->getMemoryTrimThreshold());
        } finally {
            if ($original === null) {
                unset($_ENV['SERVER_MEMORY_TRIM']);
            } else {
                $_ENV['SERVER_MEMORY_TRIM'] = $original;
            }
        }
    }

    /**
     * Settings with a known worker baseline, so the derived numbers are exact rather than
     * a function of whatever the test process happens to hold. Sixteen megabytes is what a
     * booted application carries; the framework measures it for real at startup.
     */
    private static function settings(string $limit): ServerSettings
    {
        $settings = ServerSettings::fromEnv()->memoryLimit($limit);
        new \ReflectionProperty(ServerSettings::class, 'baselineBytes')
            ->setValue($settings, 16 * 1024 ** 2);

        return $settings;
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/** A PSR-3 logger that keeps the warnings, so a test can read what was said. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string}> */
    private array $lines = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->lines[] = [$level, (string) $message];
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_map(
            static fn(array $line): string => $line[1],
            array_filter($this->lines, static fn(array $line): bool => $line[0] === 'warning'),
        ));
    }
}
