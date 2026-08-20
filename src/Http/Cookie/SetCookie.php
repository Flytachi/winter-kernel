<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Cookie;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * One `Set-Cookie` to be sent to the browser.
 *
 * Immutable and fluent: every setter returns a new instance, so a prototype can be
 * shared and specialised without anyone mutating it from under the others.
 *
 * ```
 *   SetCookie::make('sid', $token)
 *       ->expiresIn(3600)
 *       ->path('/')
 *       ->secure()
 *       ->httpOnly()
 *       ->sameSite(SameSite::Lax);
 * ```
 *
 * The header is assembled here rather than delegated to the runtime, because the two
 * runtimes disagree: Swoole's native `cookie()` writes `expires=`, `path=` and `secure`
 * in lower case and encodes the value with `+`, while PHP's `setcookie()` writes its own
 * spelling. Building the string once means Swoole and FPM emit the same bytes, and that
 * the tests asserting those bytes mean something.
 *
 * @link https://winterframe.net/docs/cookies Cookies
 * @link https://datatracker.ietf.org/doc/html/rfc6265#section-4.1 RFC 6265 — Set-Cookie
 */
final class SetCookie
{
    /** Unix timestamp, or null for a session cookie that dies with the browser. */
    private ?int $expires = null;

    /** Explicit `Max-Age`, when the lifetime was given as a duration. */
    private ?int $maxAge = null;

    private string $path = '/';
    private ?string $domain = null;
    private bool $secure = false;
    private bool $httpOnly = true;
    private ?SameSite $sameSite = SameSite::Lax;
    private bool $partitioned = false;
    private bool $raw = false;

    private function __construct(private readonly string $name, private string $value)
    {
    }

    /**
     * A cookie with safe defaults: `Path=/`, `HttpOnly`, `SameSite=Lax`, session lifetime.
     *
     * `Secure` is NOT among them — a value object cannot know the request scheme, and a
     * cookie marked secure over plain HTTP is silently dropped by the browser, which is
     * a worse failure than an honest default. {@see Cookie::make()} sets it from the
     * live request.
     *
     * @param string $name Cookie name; must be an RFC 6265 token.
     * @param string $value Value; URL-encoded on the way out unless {@see raw()} is set.
     * @throws InvalidArgumentException If the name is not a valid token.
     *
     * @link https://winterframe.net/docs/cookies#setcookiemake Building a cookie without a request
     */
    public static function make(string $name, string $value = ''): self
    {
        self::assertName($name);

        return new self($name, $value);
    }

    /**
     * A cookie that deletes `$name` — empty value, expiry in the past.
     *
     * `Path` and `Domain` must match those the cookie was set with, or the browser
     * treats it as a different cookie and the original survives.
     *
     * @param string $name Cookie to remove.
     * @param string $path Path it was set with.
     * @param string|null $domain Domain it was set with.
     *
     * @link https://winterframe.net/docs/cookies#setcookieforget A cookie that deletes another
     */
    public static function forget(string $name, string $path = '/', ?string $domain = null): self
    {
        return self::make($name)
            ->expiresAt(0)
            ->path($path)
            ->domain($domain);
    }

    // ── Lifetime ──────────────────────────────────────────────────────────────

    /**
     * Absolute expiry.
     *
     * @param DateTimeInterface|int $moment Unix timestamp, or a date to take one from.
     *
     * @link https://winterframe.net/docs/cookies#expiresat Lifetime given as a moment
     */
    public function expiresAt(DateTimeInterface|int $moment): self
    {
        $clone = clone $this;
        $clone->expires = $moment instanceof DateTimeInterface ? $moment->getTimestamp() : $moment;
        $clone->maxAge  = null;

        return $clone;
    }

    /**
     * Relative expiry — the form a browser honours most reliably.
     *
     * A negative or zero duration deletes the cookie, which is what {@see forget()}
     * relies on.
     *
     * @param int $seconds Lifetime from now.
     *
     * @link https://winterframe.net/docs/cookies#expiresin Lifetime given as a duration
     */
    public function expiresIn(int $seconds): self
    {
        $clone = clone $this;
        $clone->maxAge  = $seconds;
        $clone->expires = null;

        return $clone;
    }

    /**
     * Drops any expiry: the cookie lives until the browser closes.
     *
     * @link https://winterframe.net/docs/cookies#session A cookie that dies with the browser
     */
    public function session(): self
    {
        $clone = clone $this;
        $clone->expires = null;
        $clone->maxAge  = null;

        return $clone;
    }

    // ── Scope ─────────────────────────────────────────────────────────────────

    /**
     * URL prefix the cookie is sent for.
     *
     * @param string $path Defaults to `/` — the whole site.
     *
     * @link https://winterframe.net/docs/cookies#path The URL prefix the cookie is sent for
     */
    public function path(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    /**
     * Host the cookie belongs to.
     *
     * Omitted means "this exact host, no subdomains" — the safer of the two. A leading
     * dot is historical and ignored by modern browsers: `example.com` already covers
     * `api.example.com`.
     *
     * @param string|null $domain Domain, or null for host-only.
     *
     * @link https://winterframe.net/docs/cookies#domain Host-only versus a domain with subdomains
     */
    public function domain(?string $domain): self
    {
        $clone = clone $this;
        $clone->domain = $domain;

        return $clone;
    }

    // ── Flags ─────────────────────────────────────────────────────────────────

    /**
     * `Secure` — send over HTTPS only.
     *
     * @param bool $secure Pass false to clear it.
     *
     * @link https://winterframe.net/docs/cookies#secure HTTPS-only delivery
     */
    public function secure(bool $secure = true): self
    {
        $clone = clone $this;
        $clone->secure = $secure;

        return $clone;
    }

    /**
     * `HttpOnly` — hide the cookie from `document.cookie`.
     *
     * On by default. Turn it off only for a value the page's own JavaScript must read;
     * a session token is never that value.
     *
     * @param bool $httpOnly Pass false to expose it to scripts.
     *
     * @link https://winterframe.net/docs/cookies#httponly Hiding a cookie from JavaScript
     */
    public function httpOnly(bool $httpOnly = true): self
    {
        $clone = clone $this;
        $clone->httpOnly = $httpOnly;

        return $clone;
    }

    /**
     * `SameSite` — cross-site behaviour. See {@see SameSite}.
     *
     * @param SameSite|null $sameSite Null omits the attribute and leaves it to the browser.
     *
     * @link https://winterframe.net/docs/cookies#samesite Cross-site behaviour and CSRF
     */
    public function sameSite(?SameSite $sameSite): self
    {
        $clone = clone $this;
        $clone->sameSite = $sameSite;

        return $clone;
    }

    /**
     * `Partitioned` (CHIPS) — give the cookie a separate jar per embedding top-level
     * site, so a third-party widget cannot use it to follow the user between hosts.
     *
     * Requires `Secure`.
     *
     * @param bool $partitioned Pass false to clear it.
     *
     * @link https://winterframe.net/docs/cookies#partitioned A separate jar per embedding site
     */
    public function partitioned(bool $partitioned = true): self
    {
        $clone = clone $this;
        $clone->partitioned = $partitioned;

        return $clone;
    }

    /**
     * Send the value verbatim instead of URL-encoding it.
     *
     * For a value that is already safe — a JWT, a hex digest — and would otherwise be
     * encoded twice by an intermediary. The value must then contain no `;`, `,`,
     * whitespace, quotes or control characters; {@see toHeader()} refuses it if it does.
     *
     * @param bool $raw Pass false to go back to encoding.
     *
     * @link https://winterframe.net/docs/cookies#raw Sending a value without encoding
     */
    public function raw(bool $raw = true): self
    {
        $clone = clone $this;
        $clone->raw = $raw;

        return $clone;
    }

    /**
     * Replaces the value, keeping every attribute — the prototype case.
     *
     * @param string $value New value.
     *
     * @link https://winterframe.net/docs/cookies#value Reusing a prototype
     */
    public function value(string $value): self
    {
        $clone = clone $this;
        $clone->value = $value;

        return $clone;
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getSameSite(): ?SameSite
    {
        return $this->sameSite;
    }

    /** Absolute expiry, if one was given as a moment rather than a duration. */
    public function getExpires(): ?int
    {
        return $this->expires;
    }

    /** Lifetime in seconds, if one was given as a duration rather than a moment. */
    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    public function isPartitioned(): bool
    {
        return $this->partitioned;
    }

    public function isRaw(): bool
    {
        return $this->raw;
    }

    // ── Serialisation ─────────────────────────────────────────────────────────

    /**
     * The header value, without the `Set-Cookie:` name.
     *
     * Both attributes are emitted when a lifetime was given: `Max-Age` is what modern
     * browsers obey, `Expires` is what the oldest ones understand, and RFC 6265 says
     * `Max-Age` wins where both appear — so the pair is safe rather than contradictory.
     *
     * @param int|null $now Reference time for the relative/absolute conversion.
     *                      Defaults to the current time; tests pass a fixed one.
     * @throws InvalidArgumentException If the combination cannot be sent as-is.
     *
     * @link https://winterframe.net/docs/cookies#toheader The Set-Cookie value, and when it is refused
     */
    public function toHeader(?int $now = null): string
    {
        $this->assertSendable();

        $now   = $now ?? time();
        $value = $this->value === ''
            ? ''
            : ($this->raw ? $this->value : rawurlencode($this->value));

        $parts = [$this->name . '=' . $value];

        $expires = $this->expires ?? ($this->maxAge !== null ? $now + $this->maxAge : null);
        $maxAge  = $this->maxAge  ?? ($this->expires !== null ? $this->expires - $now : null);

        if ($expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', max(0, $expires));
            // A negative Max-Age is a parse error for some browsers, and 0 already means
            // "delete now" — which is the only thing a past expiry can mean.
            $parts[] = 'Max-Age=' . max(0, (int) $maxAge);
        }

        if ($this->domain !== null && $this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }
        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }
        if ($this->secure) {
            $parts[] = 'Secure';
        }
        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if ($this->sameSite !== null) {
            $parts[] = 'SameSite=' . $this->sameSite->value;
        }
        if ($this->partitioned) {
            $parts[] = 'Partitioned';
        }

        return implode('; ', $parts);
    }

    public function __toString(): string
    {
        return $this->toHeader();
    }

    /**
     * Fails on a cookie the browser would silently drop.
     *
     * Silent is the problem: an invalid combination costs nothing at send time and
     * shows up as "the user is randomly logged out". Checked here rather than in the
     * setters because the parts arrive one call at a time, and `->sameSite(None)`
     * before `->secure()` is a perfectly reasonable order to write.
     *
     * @throws InvalidArgumentException
     */
    public function assertSendable(): void
    {
        if ($this->sameSite === SameSite::None && !$this->secure) {
            throw new InvalidArgumentException(
                "Cookie '{$this->name}': SameSite=None requires Secure, "
                . 'or the browser discards the cookie.'
            );
        }

        if ($this->partitioned && !$this->secure) {
            throw new InvalidArgumentException(
                "Cookie '{$this->name}': Partitioned requires Secure."
            );
        }

        if ($this->raw && preg_match('/[,;\s"\x00-\x1F\x7F]/', $this->value) === 1) {
            throw new InvalidArgumentException(
                "Cookie '{$this->name}': a raw value cannot contain whitespace, quotes, "
                . "',', ';' or control characters. Drop raw() to have it encoded."
            );
        }
    }

    /**
     * @throws InvalidArgumentException If the name is not an RFC 6265 token.
     */
    private static function assertName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Cookie name cannot be empty.');
        }

        // RFC 6265 cookie-name is an RFC 2616 token: no controls, no separators.
        if (preg_match('/[=,;\s\x00-\x1F\x7F()<>@:\\\\"\/\[\]?{}]/', $name) === 1) {
            throw new InvalidArgumentException(
                "Cookie name '{$name}' contains a character that is not allowed in a token."
            );
        }
    }
}
