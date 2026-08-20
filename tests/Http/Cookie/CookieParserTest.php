<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Cookie\CookieParser;
use PHPUnit\Framework\TestCase;

/**
 * Reading the `Cookie` header.
 *
 * The parser exists because the runtimes disagree, so the tests that matter most are the
 * ones pinning the cases where PHP's own `$_COOKIE` would answer differently. Those
 * expectations were taken from a live SAPI, not from the specification: a request
 * carrying `my.sid=1; my sid=2; ok=3` arrives in `$_COOKIE` as `["my_sid", "ok"]`.
 */
final class CookieParserTest extends TestCase
{
    public function test_a_single_pair(): void
    {
        self::assertSame(['sid' => 'abc'], CookieParser::parse('sid=abc'));
    }

    public function test_several_pairs_keep_their_order(): void
    {
        self::assertSame(
            ['a' => '1', 'b' => '2', 'c' => '3'],
            CookieParser::parse('a=1; b=2; c=3'),
        );
    }

    public function test_no_header_is_no_cookies(): void
    {
        self::assertSame([], CookieParser::parse(''));
    }

    // ── Where $_COOKIE would differ ───────────────────────────────────────────

    /** The reason this class exists: PHP renames the dot, Swoole does not. */
    public function test_a_dotted_name_survives(): void
    {
        self::assertSame(['my.sid' => '1'], CookieParser::parse('my.sid=1'));
    }

    /** PHP drops this pair entirely; keeping it is what makes both modes agree. */
    public function test_a_name_with_a_space_is_kept(): void
    {
        self::assertSame(['my sid' => '2', 'ok' => '3'], CookieParser::parse('my sid=2; ok=3'));
    }

    // ── Matching $_COOKIE where it is right ───────────────────────────────────

    /** Browsers send the most specific path first, and RFC 6265 says that one wins. */
    public function test_the_first_of_two_same_named_cookies_wins(): void
    {
        self::assertSame(['a' => 'first'], CookieParser::parse('a=first; a=second'));
    }

    public function test_an_empty_value_is_a_present_cookie(): void
    {
        self::assertSame(['b' => ''], CookieParser::parse('b='));
    }

    public function test_a_bare_name_reads_as_an_empty_value(): void
    {
        self::assertSame(['c' => ''], CookieParser::parse('c'));
    }

    public function test_surrounding_whitespace_is_ignored(): void
    {
        self::assertSame(['a' => '1', 'b' => '2'], CookieParser::parse('  a=1 ;   b=2  '));
    }

    public function test_empty_segments_are_skipped(): void
    {
        self::assertSame(['a' => '1'], CookieParser::parse(';; a=1 ;;'));
    }

    // ── Decoding ──────────────────────────────────────────────────────────────

    public function test_percent_escapes_are_decoded(): void
    {
        self::assertSame(['t' => 'a b/c'], CookieParser::parse('t=a%20b%2Fc'));
    }

    /** setcookie() writes a space as `+`, and those cookies come back to us too. */
    public function test_a_plus_is_decoded_as_a_space(): void
    {
        self::assertSame(['t' => 'a b'], CookieParser::parse('t=a+b'));
    }

    public function test_a_value_containing_an_equals_sign_is_kept_whole(): void
    {
        // Base64 padding is the everyday case.
        self::assertSame(['t' => 'YWJj=='], CookieParser::parse('t=YWJj=='));
    }

    public function test_a_round_trip_through_setcookie_encoding(): void
    {
        $value  = 'значение с пробелом/слэшем';
        $header = 't=' . rawurlencode($value);

        self::assertSame(['t' => $value], CookieParser::parse($header));
    }
}
