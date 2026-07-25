<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Schedule\Trigger;

use Flytachi\Winter\K2\Schedule\Trigger\CronTrigger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronTriggerTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        // Fix the timezone so wall-clock expectations are deterministic.
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private function next(string $expr, int $fromTs): int
    {
        return (int) new CronTrigger($expr)->nextFireTime((float) $fromTs, null, null);
    }

    public function test_daily_at_night(): void
    {
        // Mon 2026-06-15 10:30:15 → next 02:00 is the following day.
        $now = mktime(10, 30, 15, 6, 15, 2026);
        self::assertSame(mktime(2, 0, 0, 6, 16, 2026), $this->next('0 2 * * *', $now));
    }

    public function test_every_minute_and_hour(): void
    {
        $now = mktime(10, 30, 15, 6, 15, 2026);
        self::assertSame(mktime(10, 31, 0, 6, 15, 2026), $this->next('* * * * *', $now));
        self::assertSame(mktime(11, 0, 0, 6, 15, 2026), $this->next('0 * * * *', $now));
    }

    public function test_step_and_list(): void
    {
        $now = mktime(10, 30, 15, 6, 15, 2026);
        self::assertSame(mktime(10, 45, 0, 6, 15, 2026), $this->next('*/15 * * * *', $now));
        self::assertSame(mktime(18, 30, 0, 6, 15, 2026), $this->next('30 6,18 * * *', $now));
    }

    public function test_weekday_range_skips_the_weekend(): void
    {
        // Sat 2026-06-13 09:00 → 08:00 on weekdays lands on Mon 2026-06-15.
        $sat = mktime(9, 0, 0, 6, 13, 2026);
        self::assertSame(mktime(8, 0, 0, 6, 15, 2026), $this->next('0 8 * * 1-5', $sat));
    }

    public function test_leap_day_resolves_across_years(): void
    {
        $now = mktime(10, 30, 0, 6, 15, 2026);
        self::assertSame(mktime(0, 0, 0, 2, 29, 2028), $this->next('0 0 29 2 *', $now));
    }

    public function test_macro_matches_expanded_form(): void
    {
        $now = mktime(10, 30, 15, 6, 15, 2026);
        self::assertSame($this->next('0 * * * *', $now), $this->next('@hourly', $now));
        self::assertSame($this->next('0 0 * * *', $now), $this->next('@daily', $now));
    }

    public function test_sunday_is_both_zero_and_seven(): void
    {
        $now = mktime(10, 30, 15, 6, 15, 2026); // Monday
        self::assertSame($this->next('0 0 * * 0', $now), $this->next('0 0 * * 7', $now));
    }

    public function test_first_fire_ignores_initial_delay(): void
    {
        $now = mktime(10, 30, 15, 6, 15, 2026);
        $trigger = new CronTrigger('0 2 * * *');
        self::assertSame(
            $trigger->nextFireTime((float) $now, null, null),
            $trigger->firstFireTime((float) $now, 9999.0)
        );
    }

    public function test_describe(): void
    {
        self::assertSame('cron 0 2 * * *', new CronTrigger('0 2 * * *')->describe());
    }

    /**
     * @return list<array{string}>
     */
    public static function malformed(): array
    {
        return [[''], ['1 2 3'], ['60 * * * *'], ['* * * * 9'], ['a b c d e'], ['*/0 * * * *'], ['5-1 * * * *']];
    }

    #[DataProvider('malformed')]
    public function test_malformed_expressions_throw(string $expr): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CronTrigger($expr);
    }
}
