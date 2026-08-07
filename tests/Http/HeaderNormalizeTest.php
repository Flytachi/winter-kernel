<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http;

use Flytachi\Winter\Kernel\Http\Header;
use Flytachi\Winter\Kernel\Tests\Http\Fixtures\OriginProbeRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Header-name normalisation is memoised, and the memo cannot be grown without bound.
 *
 * The normalisation itself is four string operations per key, repeated for every key of
 * every request even though real traffic reuses the same few dozen names. Memoising it
 * is obvious; doing so safely is not, because **header names come from the client**. An
 * unbounded map would let one caller grow a long-lived Swoole worker's memory until it
 * dies — so the memo is capped, and past the cap normalisation simply happens as before.
 */
final class HeaderNormalizeTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function keys(): array
    {
        return [
            'lower case' => ['content-type', 'Content-Type'],
            'upper case' => ['CONTENT-TYPE', 'Content-Type'],
            'mixed case' => ['CoNtEnT-TyPe', 'Content-Type'],
            'already normal' => ['Content-Type', 'Content-Type'],
            'single word' => ['host', 'Host'],
            'three parts' => ['x-forwarded-for', 'X-Forwarded-For'],
            'no separator' => ['authorization', 'Authorization'],
        ];
    }

    #[DataProvider('keys')]
    public function test_a_key_normalises_to_title_case(string $raw, string $expected): void
    {
        self::assertSame($expected, self::normalize($raw));
    }

    public function test_repeated_normalisation_is_stable(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertSame('Accept-Language', self::normalize('accept-language'));
        }
    }

    /**
     * The safety property: a flood of distinct names must not grow the memo past its cap,
     * and every one of them must still normalise correctly.
     */
    public function test_a_flood_of_distinct_names_stays_bounded_and_correct(): void
    {
        $limit = new \ReflectionClassConstant(Header::class, 'NORMALIZE_MEMO_LIMIT')->getValue();

        for ($i = 0; $i < $limit * 4; $i++) {
            self::assertSame("X-Flood-{$i}", self::normalize("x-flood-{$i}"));
        }

        self::assertLessThanOrEqual(
            $limit,
            self::memoSize(),
            'The memo must stop growing at its cap — its keys are client-controlled.',
        );
    }

    /**
     * Reading a header goes through the same normalisation, so the cap must not change
     * what a lookup finds.
     */
    public function test_lookup_still_works_for_a_key_beyond_the_cap(): void
    {
        Header::init(new OriginProbeRequest('http', 'localhost', 80, 'http://localhost', [
            'x-late-header' => 'value',
        ]));

        self::assertSame('value', Header::get('X-Late-Header'));
        self::assertSame('value', Header::get('x-late-header'));
    }

    private static function normalize(string $key): string
    {
        return new ReflectionMethod(Header::class, 'normalizeKey')->invoke(null, $key);
    }

    private static function memoSize(): int
    {
        $statics = new ReflectionMethod(Header::class, 'normalizeKey')->getStaticVariables();

        return count($statics['memo'] ?? []);
    }
}
