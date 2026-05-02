<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\Msisdn;
use Flytachi\Winter\K2\Http\Request\Validation\Phone;
use PHPUnit\Framework\TestCase;

class MsisdnPhoneTest extends TestCase
{
    // ── #[Msisdn] ─────────────────────────────────────────────────────────────

    public function testMsisdnNullPasses(): void
    {
        self::assertNull((new Msisdn())->validate(null, 'field'));
    }

    public function testValidMsisdnPasses(): void
    {
        self::assertNull((new Msisdn())->validate('79001234567', 'field'));
    }

    public function testMinLengthMsisdnPasses(): void
    {
        self::assertNull((new Msisdn())->validate('1234567', 'field'));
    }

    public function testMaxLengthMsisdnPasses(): void
    {
        self::assertNull((new Msisdn())->validate('123456789012345', 'field'));
    }

    public function testMsisdnWithPlusFails(): void
    {
        self::assertNotNull((new Msisdn())->validate('+79001234567', 'field'));
    }

    public function testMsisdnTooShortFails(): void
    {
        self::assertNotNull((new Msisdn())->validate('12345', 'field'));
    }

    public function testMsisdnTooLongFails(): void
    {
        self::assertNotNull((new Msisdn())->validate('1234567890123456', 'field'));
    }

    public function testMsisdnWithLettersFails(): void
    {
        self::assertNotNull((new Msisdn())->validate('7900abc4567', 'field'));
    }

    // ── #[Phone] ──────────────────────────────────────────────────────────────

    public function testPhoneNullPasses(): void
    {
        self::assertNull((new Phone())->validate(null, 'field'));
    }

    public function testPhoneWithPlusPasses(): void
    {
        self::assertNull((new Phone())->validate('+79001234567', 'field'));
    }

    public function testPhoneFormattedPasses(): void
    {
        self::assertNull((new Phone())->validate('+7 (900) 123-45-67', 'field'));
    }

    public function testPhoneDigitsOnlyPasses(): void
    {
        self::assertNull((new Phone())->validate('79001234567', 'field'));
    }

    public function testPhoneWithLettersFails(): void
    {
        self::assertNotNull((new Phone())->validate('not-a-phone', 'field'));
    }

    public function testPhoneTooShortFails(): void
    {
        self::assertNotNull((new Phone())->validate('123', 'field'));
    }
}
