<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Cookie;

use Closure;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Header;
use LogicException;

/**
 * Unified cookie accessor — dual-mode, same API for Swoole and FPM.
 *
 * Initialised once per request by the Router, next to {@see Header}:
 *   Cookie::init($request, $response)
 *
 * Then, from anywhere in the request:
 *   Cookie::get('sid')
 *   Cookie::add(Cookie::make('sid', $token)->expiresIn(3600))
 *   Cookie::forget('sid')
 *
 * Swoole: stored in Coroutine::getContext() — coroutine-safe, isolated per request.
 * FPM:    stored in a static — process-wide, which is the whole request there.
 *
 * Cookies are written to the response the moment {@see add()} is called, exactly as a
 * header is. Nothing is queued for a later flush, so a cookie set by a middleware
 * survives whatever the handler does afterwards — including throwing, which is precisely
 * when clearing a session cookie matters most.
 *
 * @link https://winterframe.net/docs/cookies Cookies
 */
final class Cookie
{
    private const CTX_KEY = '__k2_cookies__';
    private const CTX_RES = '__k2_cookie_response__';

    /** FPM fallback storage for the request's cookies. */
    private static array $bag = [];

    /** FPM fallback storage for the response cookies are written to. */
    private static ?HttpResponse $response = null;

    /** Application-wide defaults applied by {@see make()}. */
    private static ?Closure $defaults = null;

    private function __construct()
    {
    }

    // ── Initialization ────────────────────────────────────────────────────────

    /**
     * Call once per request, before any handler runs.
     *
     * @param HttpRequest $request Request to read the `Cookie` header from.
     * @param HttpResponse $response Response that {@see add()} writes to.
     */
    public static function init(HttpRequest $request, HttpResponse $response): void
    {
        $cookies = $request->getCookies();

        if (Runtime::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            $ctx[self::CTX_KEY] = $cookies;
            $ctx[self::CTX_RES] = $response;
        } else {
            self::$bag      = $cookies;
            self::$response = $response;
        }
    }

    /**
     * Attributes every cookie from {@see make()} starts with.
     *
     * Set once at boot, when the whole application shares a domain or a stricter
     * `SameSite` than the default:
     * ```
     *   Cookie::defaults(fn(SetCookie $c) => $c->domain('.example.com')->sameSite(SameSite::Strict));
     * ```
     *
     * A customiser rather than a fixed prototype, because it composes: it runs after the
     * scheme-derived `Secure`, so an application can still override that too.
     *
     * @param Closure(SetCookie): SetCookie|null $configure Null clears the defaults.
     *
     * @link https://winterframe.net/docs/cookies#cookiedefaults Application-wide defaults
     */
    public static function defaults(?Closure $configure): void
    {
        self::$defaults = $configure;
    }

    /** Drops request state. Used between requests under FPM and by tests. */
    public static function clear(): void
    {
        self::$bag      = [];
        self::$response = null;
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /**
     * @param string $name Cookie name, case-sensitive as the client sent it.
     * @return string|null Decoded value, or null if the client sent no such cookie.
     *
     * @link https://winterframe.net/docs/cookies#cookieget Reading a cookie the client sent
     */
    public static function get(string $name): ?string
    {
        return self::storage()[$name] ?? null;
    }

    /**
     * @param string $name Cookie name.
     * @return bool Whether the client sent it — true even when the value is empty.
     *
     * @link https://winterframe.net/docs/cookies#cookiehas Presence versus an empty value
     */
    public static function has(string $name): bool
    {
        return array_key_exists($name, self::storage());
    }

    /**
     * @return array<string, string> Every cookie the client sent.
     *
     * @link https://winterframe.net/docs/cookies#cookieall Every cookie of the request
     */
    public static function all(): array
    {
        return self::storage();
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * A cookie carrying the application's defaults and the request's scheme.
     *
     * `Secure` is set when the request arrived over HTTPS — the one attribute that
     * cannot be decided by a value object, and the one that silently breaks a cookie
     * when guessed wrong in either direction.
     *
     * @param string $name Cookie name.
     * @param string $value Value.
     *
     * @link https://winterframe.net/docs/cookies#cookiemake Building a cookie with request and application defaults
     */
    public static function make(string $name, string $value = ''): SetCookie
    {
        $cookie = SetCookie::make($name, $value)->secure(Header::getScheme() === 'https');

        return self::$defaults !== null ? (self::$defaults)($cookie) : $cookie;
    }

    /**
     * Writes the cookie to the response.
     *
     * @param SetCookie $cookie Cookie to send.
     * @throws LogicException If no response is bound — outside a request there is
     *                        nothing to write to, and silently dropping the cookie
     *                        would look like the browser ignored it.
     *
     * @link https://winterframe.net/docs/cookies#cookieadd Sending a cookie, and why it is written at once
     */
    public static function add(SetCookie $cookie): void
    {
        $response = self::response();
        if ($response === null) {
            throw new LogicException(
                'Cookie::add() outside a request: Cookie::init() has not run. '
                . 'Build the cookie with SetCookie::make() and attach it to a '
                . 'ResponseEntity instead.'
            );
        }

        $response->cookie($cookie);
    }

    /**
     * Tells the browser to drop a cookie.
     *
     * Path and domain have to match the ones it was set with, or the browser sees a
     * different cookie and keeps the original.
     *
     * @param string $name Cookie to remove.
     * @param string $path Path it was set with.
     * @param string|null $domain Domain it was set with.
     *
     * @link https://winterframe.net/docs/cookies#cookieforget Deleting a cookie, and why path and domain must match
     */
    public static function forget(string $name, string $path = '/', ?string $domain = null): void
    {
        self::add(SetCookie::forget($name, $path, $domain));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private static function storage(): array
    {
        if (Runtime::isSwooleCoroutine()) {
            return \Swoole\Coroutine::getContext()[self::CTX_KEY] ?? [];
        }

        return self::$bag;
    }

    private static function response(): ?HttpResponse
    {
        if (Runtime::isSwooleCoroutine()) {
            return \Swoole\Coroutine::getContext()[self::CTX_RES] ?? null;
        }

        return self::$response;
    }
}
