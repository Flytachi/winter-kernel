<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request\Validation;

use Flytachi\Winter\Kernel\Http\Request\Validation\Uuid;
use PHPUnit\Framework\TestCase;

class UuidTest extends TestCase
{
    // ── any version ───────────────────────────────────────────────────────────

    public function testNullPasses(): void
    {
        self::assertNull((new Uuid())->validate(null, 'field'));
    }

    public function testValidUuidPasses(): void
    {
        self::assertNull((new Uuid())->validate('550e8400-e29b-41d4-a716-446655440000', 'field'));
    }

    public function testUppercaseUuidPasses(): void
    {
        self::assertNull((new Uuid())->validate('550E8400-E29B-41D4-A716-446655440000', 'field'));
    }

    public function testUuidV4Passes(): void
    {
        self::assertNull((new Uuid())->validate('f47ac10b-58cc-4372-a567-0e02b2c3d479', 'field'));
    }

    public function testPlainStringFails(): void
    {
        self::assertSame('must be a valid UUID', (new Uuid())->validate('not-a-uuid', 'field'));
    }

    public function testUuidWithoutDashesFails(): void
    {
        self::assertSame('must be a valid UUID', (new Uuid())->validate('550e8400e29b41d4a716446655440000', 'field'));
    }

    public function testEmptyStringFails(): void
    {
        self::assertSame('must be a valid UUID', (new Uuid())->validate('', 'field'));
    }

    public function testTooShortFails(): void
    {
        self::assertSame('must be a valid UUID', (new Uuid())->validate('550e8400-e29b-41d4-a716', 'field'));
    }

    // ── version-specific ─────────────────────────────────────────────────────

    public function testV4MatchesPasses(): void
    {
        self::assertNull((new Uuid(4))->validate('f47ac10b-58cc-4372-a567-0e02b2c3d479', 'field'));
    }

    public function testV4WrongVersionFails(): void
    {
        // version digit is 1, not 4
        self::assertSame(
            'must be a valid UUID v4',
            (new Uuid(4))->validate('550e8400-e29b-11d4-a716-446655440000', 'field')
        );
    }

    public function testV1MatchesPasses(): void
    {
        self::assertNull((new Uuid(1))->validate('550e8400-e29b-11d4-a716-446655440000', 'field'));
    }

    public function testVersionMessageIncludesVersion(): void
    {
        $result = (new Uuid(7))->validate('not-a-uuid', 'field');
        self::assertSame('must be a valid UUID v7', $result);
    }
}
