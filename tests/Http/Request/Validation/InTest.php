<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request\Validation;

use Flytachi\Winter\K2\Http\Request\Validation\In;
use PHPUnit\Framework\TestCase;

class InTest extends TestCase
{
    public function testNullPasses(): void
    {
        self::assertNull((new In(['a', 'b']))->validate(null, 'field'));
    }

    // ── string list ───────────────────────────────────────────────────────────

    public function testValueInListPasses(): void
    {
        self::assertNull((new In(['active', 'inactive', 'banned']))->validate('active', 'field'));
    }

    public function testValueNotInListFails(): void
    {
        self::assertSame(
            'must be one of ["active", "inactive", "banned"]',
            (new In(['active', 'inactive', 'banned']))->validate('unknown', 'field')
        );
    }

    // ── int list ──────────────────────────────────────────────────────────────

    public function testIntInListPasses(): void
    {
        self::assertNull((new In([1, 2, 3]))->validate(2, 'field'));
    }

    public function testIntNotInListFails(): void
    {
        self::assertSame(
            'must be one of [1, 2, 3]',
            (new In([1, 2, 3]))->validate(5, 'field')
        );
    }

    // ── strict mode (default) ─────────────────────────────────────────────────

    public function testStrictModeRejectsTypeCoercion(): void
    {
        // "1" (string) !== 1 (int) in strict mode
        self::assertSame(
            'must be one of [1, 2, 3]',
            (new In([1, 2, 3]))->validate('1', 'field')
        );
    }

    // ── loose mode ────────────────────────────────────────────────────────────

    public function testLooseModeAcceptsTypeCoercion(): void
    {
        // "1" == 1 in loose mode
        self::assertNull((new In([1, 2, 3], strict: false))->validate('1', 'field'));
    }

    // ── message format ────────────────────────────────────────────────────────

    public function testMixedListMessage(): void
    {
        self::assertSame(
            'must be one of ["yes", "no"]',
            (new In(['yes', 'no']))->validate('maybe', 'field')
        );
    }
}
