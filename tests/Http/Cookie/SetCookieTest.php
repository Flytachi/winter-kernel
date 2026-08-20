<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Cookie;

use Flytachi\Winter\Kernel\Http\Cookie\SameSite;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The bytes of a `Set-Cookie`.
 *
 * Both runtimes serialise through this one method, so these assertions are what makes
 * "Swoole and FPM send the same header" true rather than hoped for. The reference time
 * is passed in, so a header with an expiry is as deterministic as one without.
 */
final class SetCookieTest extends TestCase
{
    /** 2025-08-19 10:40:00 UTC — any fixed point; the assertions read it back. */
    private const int NOW = 1755600000;

    // ── Defaults ──────────────────────────────────────────────────────────────

    public function test_a_plain_cookie_carries_the_safe_defaults(): void
    {
        self::assertSame(
            'sid=abc; Path=/; HttpOnly; SameSite=Lax',
            SetCookie::make('sid', 'abc')->toHeader(self::NOW),
        );
    }

    /**
     * Secure is not a default: a value object cannot see the request scheme, and a
     * secure cookie sent over plain HTTP is dropped by the browser without a word.
     */
    public function test_secure_is_not_assumed(): void
    {
        self::assertFalse(SetCookie::make('sid')->isSecure());
        self::assertStringNotContainsString('Secure', SetCookie::make('sid')->toHeader(self::NOW));
    }

    // ── Lifetime ──────────────────────────────────────────────────────────────

    public function test_a_duration_becomes_both_max_age_and_expires(): void
    {
        // Max-Age is what a modern browser obeys; Expires is what the oldest understand.
        // RFC 6265 gives Max-Age precedence, so the pair is safe rather than ambiguous.
        self::assertSame(
            'sid=abc; Expires=Tue, 19 Aug 2025 11:40:00 GMT; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax',
            SetCookie::make('sid', 'abc')->expiresIn(3600)->toHeader(self::NOW),
        );
    }

    public function test_a_moment_becomes_both_expires_and_max_age(): void
    {
        self::assertSame(
            'sid=abc; Expires=Tue, 19 Aug 2025 11:40:00 GMT; Max-Age=3600; Path=/; HttpOnly; SameSite=Lax',
            SetCookie::make('sid', 'abc')->expiresAt(self::NOW + 3600)->toHeader(self::NOW),
        );
    }

    public function test_a_datetime_is_accepted_as_a_moment(): void
    {
        $at = new \DateTimeImmutable('@' . (self::NOW + 60));

        self::assertStringContainsString('Max-Age=60', SetCookie::make('a')->expiresAt($at)->toHeader(self::NOW));
    }

    public function test_a_session_cookie_states_no_lifetime(): void
    {
        $header = SetCookie::make('a', 'b')->expiresIn(60)->session()->toHeader(self::NOW);

        self::assertStringNotContainsString('Expires', $header);
        self::assertStringNotContainsString('Max-Age', $header);
    }

    /** A past expiry means "delete"; a negative Max-Age is a parse error for some clients. */
    public function test_a_past_expiry_never_emits_a_negative_max_age(): void
    {
        self::assertStringContainsString(
            'Max-Age=0',
            SetCookie::make('a', 'b')->expiresAt(self::NOW - 9999)->toHeader(self::NOW),
        );
    }

    public function test_forget_builds_a_deletion_cookie(): void
    {
        self::assertSame(
            'sid=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
            SetCookie::forget('sid')->toHeader(self::NOW),
        );
    }

    /**
     * Path and domain have to be repeated on deletion: to a browser they are part of the
     * cookie's identity, so a mismatch removes nothing and leaves the user logged in.
     */
    public function test_forget_carries_the_scope_it_was_given(): void
    {
        self::assertSame(
            'sid=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; '
            . 'Domain=example.com; Path=/admin; HttpOnly; SameSite=Lax',
            SetCookie::forget('sid', '/admin', 'example.com')->toHeader(self::NOW),
        );
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    public function test_every_attribute_appears_in_canonical_order(): void
    {
        self::assertSame(
            'w=x; Expires=Tue, 19 Aug 2025 11:40:00 GMT; Max-Age=3600; '
            . 'Domain=example.com; Path=/app; Secure; HttpOnly; SameSite=None; Partitioned',
            SetCookie::make('w', 'x')
                ->expiresIn(3600)
                ->domain('example.com')
                ->path('/app')
                ->secure()
                ->httpOnly()
                ->sameSite(SameSite::None)
                ->partitioned()
                ->toHeader(self::NOW),
        );
    }

    public function test_httponly_can_be_dropped_for_a_value_scripts_must_read(): void
    {
        self::assertSame(
            'theme=dark; Path=/; SameSite=Lax',
            SetCookie::make('theme', 'dark')->httpOnly(false)->toHeader(self::NOW),
        );
    }

    public function test_samesite_can_be_omitted_entirely(): void
    {
        self::assertSame(
            'a=b; Path=/; HttpOnly',
            SetCookie::make('a', 'b')->sameSite(null)->toHeader(self::NOW),
        );
    }

    public function test_an_empty_domain_is_treated_as_no_domain(): void
    {
        self::assertStringNotContainsString('Domain', SetCookie::make('a', 'b')->domain('')->toHeader(self::NOW));
    }

    // ── Encoding ──────────────────────────────────────────────────────────────

    public function test_a_value_is_url_encoded(): void
    {
        self::assertStringStartsWith('t=a%20b%2Fc;', SetCookie::make('t', 'a b/c')->toHeader(self::NOW));
    }

    public function test_a_raw_value_is_sent_untouched(): void
    {
        // A JWT is already safe and would otherwise be encoded a second time.
        self::assertStringStartsWith(
            'jwt=eyJhbGci.payload.sig;',
            SetCookie::make('jwt', 'eyJhbGci.payload.sig')->raw()->toHeader(self::NOW),
        );
    }

    public function test_an_empty_value_stays_empty_rather_than_encoded(): void
    {
        self::assertStringStartsWith('a=;', SetCookie::make('a', '')->toHeader(self::NOW));
    }

    // ── Immutability ──────────────────────────────────────────────────────────

    /** The prototype case: shared defaults must survive being specialised. */
    public function test_setters_return_a_new_instance(): void
    {
        $base    = SetCookie::make('a', 'b');
        $derived = $base->secure()->path('/admin');

        self::assertFalse($base->isSecure(), 'the original is untouched');
        self::assertSame('/', $base->getPath());
        self::assertTrue($derived->isSecure());
        self::assertSame('/admin', $derived->getPath());
    }

    public function test_value_replaces_the_value_and_keeps_the_attributes(): void
    {
        $proto = SetCookie::make('sid', 'one')->secure()->path('/app');
        $next  = $proto->value('two');

        self::assertSame('two', $next->getValue());
        self::assertSame('/app', $next->getPath());
        self::assertTrue($next->isSecure());
        self::assertSame('one', $proto->getValue());
    }

    // ── Refusals ──────────────────────────────────────────────────────────────

    public function test_samesite_none_without_secure_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SameSite=None requires Secure');

        SetCookie::make('a', 'b')->sameSite(SameSite::None)->toHeader(self::NOW);
    }

    public function test_partitioned_without_secure_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Partitioned requires Secure');

        SetCookie::make('a', 'b')->partitioned()->toHeader(self::NOW);
    }

    /**
     * Order must not matter: the parts arrive one call at a time, and writing
     * ->sameSite(None) before ->secure() is perfectly reasonable.
     */
    public function test_the_order_of_secure_and_samesite_is_irrelevant(): void
    {
        $header = SetCookie::make('a', 'b')->sameSite(SameSite::None)->secure()->toHeader(self::NOW);

        self::assertStringContainsString('Secure; HttpOnly; SameSite=None', $header);
    }

    public function test_a_raw_value_with_a_separator_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Unencoded, this would end the cookie early and the rest would read as attributes.
        SetCookie::make('a', 'one; Path=/evil')->raw()->toHeader(self::NOW);
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SetCookie::make('');
    }

    /**
     * @param string $name A name no browser would accept.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('illegalNames')]
    public function test_a_name_outside_the_token_grammar_is_refused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        SetCookie::make($name, 'x');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalNames(): iterable
    {
        yield 'space'      => ['my sid'];
        yield 'equals'     => ['a=b'];
        yield 'semicolon'  => ['a;b'];
        yield 'comma'      => ['a,b'];
        yield 'newline'    => ["a\nb"];
        yield 'parens'     => ['a(b)'];
        yield 'slash'      => ['a/b'];
        yield 'quote'      => ['a"b'];
    }

    /** A dot is legal in a token, and it is exactly what PHP's $_COOKIE would rewrite. */
    public function test_a_dotted_name_is_allowed(): void
    {
        self::assertStringStartsWith('my.sid=1;', SetCookie::make('my.sid', '1')->toHeader(self::NOW));
    }

    public function test_the_string_cast_is_the_header(): void
    {
        $cookie = SetCookie::make('a', 'b');

        self::assertSame($cookie->toHeader(), (string) $cookie);
    }
}
