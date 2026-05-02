<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Date;
use Flytachi\Winter\K2\Http\Request\Validation\Datetime;
use Flytachi\Winter\K2\Http\Request\Validation\Time;
use PHPUnit\Framework\TestCase;

class DateTimeTest extends TestCase
{
    // ── DateTimeInterface objects (already-hydrated fields) ───────────────────

    public function testDateAcceptsDateTimeImmutable(): void
    {
        self::assertNull((new Date())->validate(new \DateTimeImmutable('2024-01-31'), 'field'));
    }

    public function testDateAcceptsDateTime(): void
    {
        self::assertNull((new Date())->validate(new \DateTime('2024-01-31'), 'field'));
    }

    public function testTimeAcceptsDateTimeImmutable(): void
    {
        self::assertNull((new Time())->validate(new \DateTimeImmutable('2024-01-31 14:30:00'), 'field'));
    }

    public function testDatetimeAcceptsDateTimeImmutable(): void
    {
        self::assertNull((new Datetime())->validate(new \DateTimeImmutable('2024-01-31T14:30:00'), 'field'));
    }

    // ── #[Date] ───────────────────────────────────────────────────────────────

    public function testDateNullPasses(): void
    {
        self::assertNull((new Date())->validate(null, 'field'));
    }

    public function testValidDatePasses(): void
    {
        self::assertNull((new Date())->validate('2024-01-31', 'field'));
    }

    public function testInvalidMonthFails(): void
    {
        self::assertSame('must be a valid date (Y-m-d)', (new Date())->validate('2024-13-01', 'field'));
    }

    public function testInvalidDayFails(): void
    {
        self::assertSame('must be a valid date (Y-m-d)', (new Date())->validate('2024-02-30', 'field'));
    }

    public function testWrongFormatFails(): void
    {
        self::assertSame('must be a valid date (Y-m-d)', (new Date())->validate('31.01.2024', 'field'));
    }

    public function testCustomFormatPasses(): void
    {
        self::assertNull((new Date('d.m.Y'))->validate('31.01.2024', 'field'));
    }

    public function testCustomFormatFails(): void
    {
        self::assertSame('must be a valid date (d.m.Y)', (new Date('d.m.Y'))->validate('2024-01-31', 'field'));
    }

    // ── #[Time] ───────────────────────────────────────────────────────────────

    public function testTimeNullPasses(): void
    {
        self::assertNull((new Time())->validate(null, 'field'));
    }

    public function testTimeHiPasses(): void
    {
        self::assertNull((new Time())->validate('14:30', 'field'));
    }

    public function testTimeHisPasses(): void
    {
        self::assertNull((new Time())->validate('14:30:00', 'field'));
    }

    public function testInvalidTimeFails(): void
    {
        self::assertSame('must be a valid time (H:i or H:i:s)', (new Time())->validate('25:00', 'field'));
    }

    public function testTimeCustomFormatPasses(): void
    {
        self::assertNull((new Time('H:i'))->validate('14:30', 'field'));
    }

    public function testTimeCustomFormatFails(): void
    {
        self::assertSame('must be a valid time (H:i)', (new Time('H:i'))->validate('14:30:00', 'field'));
    }

    // ── #[Datetime] ───────────────────────────────────────────────────────────

    public function testDatetimeNullPasses(): void
    {
        self::assertNull((new Datetime())->validate(null, 'field'));
    }

    public function testIso8601Passes(): void
    {
        self::assertNull((new Datetime())->validate('2024-01-31T14:30:00', 'field'));
    }

    public function testDatetimeWithTimezonePasses(): void
    {
        self::assertNull((new Datetime())->validate('2024-01-31T14:30:00+03:00', 'field'));
    }

    public function testInvalidDatetimeFails(): void
    {
        self::assertSame('must be a valid datetime', (new Datetime())->validate('not-a-date', 'field'));
    }

    public function testDatetimeCustomFormatPasses(): void
    {
        self::assertNull((new Datetime('Y-m-d H:i:s'))->validate('2024-01-31 14:30:00', 'field'));
    }

    public function testDatetimeCustomFormatFails(): void
    {
        self::assertSame(
            'must be a valid datetime (Y-m-d H:i:s)',
            (new Datetime('Y-m-d H:i:s'))->validate('2024-01-31T14:30:00', 'field')
        );
    }
}
