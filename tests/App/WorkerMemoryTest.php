<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

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
        $options = ServerSettings::fromEnv()->workers(4)->memoryLimit('256M')->toArray();

        self::assertArrayHasKey('worker_num', $options);
        self::assertArrayNotHasKey('memory_limit', $options);
        self::assertArrayNotHasKey('memoryLimit', $options);
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
