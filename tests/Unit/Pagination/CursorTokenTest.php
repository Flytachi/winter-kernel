<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Unit\Pagination;

use Flytachi\Winter\Kernel\Unit\Pagination\CursorDirection;
use Flytachi\Winter\Kernel\Unit\Pagination\CursorToken;
use Flytachi\Winter\Kernel\Unit\Pagination\InvalidCursorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cursor token is **client-supplied input**: it travels in a URL and anyone can
 * rewrite it. It is base64-encoded JSON, not a signed envelope — the `s` field pins the
 * *shape* of the key, not its authenticity — so `decode()` is the only thing standing
 * between a forged token and the query builder.
 *
 * Position values are bound as parameters downstream, so a forgery cannot inject SQL.
 * What it can do is hand the builder something it does not accept, which is why every
 * malformed shape has to come back as `InvalidCursorException` (a 400) and never as a
 * raw `TypeError` (a 500).
 */
final class CursorTokenTest extends TestCase
{
    private const string SIGNATURE = 'id:asc,created_at:desc';

    public function test_a_token_survives_a_round_trip(): void
    {
        $token = CursorToken::encode(self::SIGNATURE, [42, '2026-01-01'], CursorDirection::Forward);

        [$values, $direction] = CursorToken::decode($token, self::SIGNATURE);

        self::assertSame([42, '2026-01-01'], $values);
        self::assertSame(CursorDirection::Forward, $direction);
    }

    public function test_the_direction_round_trips_both_ways(): void
    {
        foreach ([CursorDirection::Forward, CursorDirection::Backward] as $direction) {
            $token = CursorToken::encode(self::SIGNATURE, [1], $direction);

            self::assertSame($direction, CursorToken::decode($token, self::SIGNATURE)[1]);
        }
    }

    public function test_scalar_and_null_positions_are_preserved(): void
    {
        $values = [1, 'text', 1.5, true, null];
        $token  = CursorToken::encode(self::SIGNATURE, $values, CursorDirection::Forward);

        self::assertSame($values, CursorToken::decode($token, self::SIGNATURE)[0]);
    }

    public function test_a_cursor_from_another_key_shape_is_refused(): void
    {
        // Reusing a cursor across queries would silently paginate by the wrong columns.
        $token = CursorToken::encode('id:asc', [1], CursorDirection::Forward);

        $this->expectException(InvalidCursorException::class);
        $this->expectExceptionMessage('signature mismatch');

        CursorToken::decode($token, self::SIGNATURE);
    }

    /** @return array<string, array{0: string}> */
    public static function forgeries(): array
    {
        $wrap = static fn(array $payload): string => base64_encode((string) json_encode($payload));

        return [
            'not base64'          => ['!!! not base64 !!!'],
            'not json'            => [base64_encode('plain text')],
            'json but not object' => [base64_encode('[1,2,3]')],
            'missing fields'      => [$wrap(['s' => self::SIGNATURE])],
            'values not a list'   => [$wrap(['s' => self::SIGNATURE, 'v' => 'nope', 'd' => 'f'])],
            'unknown direction'   => [$wrap(['s' => self::SIGNATURE, 'v' => [1], 'd' => 'sideways'])],
            'nested array value'  => [$wrap(['s' => self::SIGNATURE, 'v' => [[1, 2]], 'd' => 'f'])],
            'object value'        => [$wrap(['s' => self::SIGNATURE, 'v' => [['a' => 1]], 'd' => 'f'])],
        ];
    }

    #[DataProvider('forgeries')]
    public function test_a_forged_token_is_refused_as_an_invalid_cursor(string $token): void
    {
        // The type matters as much as the rejection: callers catch InvalidCursorException
        // to answer 400. Anything else escapes as a 500.
        $this->expectException(InvalidCursorException::class);

        CursorToken::decode($token, self::SIGNATURE);
    }
}
