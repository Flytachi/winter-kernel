<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\Base\Runtime;

/**
 * Unified HTTP header accessor — dual-mode, same API for Swoole and FPM.
 *
 * Call once per request:
 *   Header::init(HttpRequest $request)
 *
 * Then read from anywhere:
 *   Header::get('Authorization')
 *   Header::getBearerToken()
 *   Header::getIpAddress()
 *   Header::getBaseUrl()
 *
 * Swoole: stores in Coroutine::getContext() — coroutine-safe, per-request isolation.
 * FPM:    stores in a static array — process-wide, safe for single-request lifecycle.
 */
final class Header
{
    private const CTX_KEY    = '__k2_headers__';
    private const CTX_ORIGIN = '__k2_origin__';

    /** FPM fallback storage */
    private static array $bag = [];

    /** FPM fallback storage for the request origin (scheme/host/port/baseUrl). */
    private static array $origin = [];

    private function __construct()
    {
    }

    // ── Initialization ────────────────────────────────────────────────────────

    /** Call once per request in the on('request') handler or index.php entry point. */
    public static function init(HttpRequest $request): void
    {
        $headers = self::normalizeMap($request->getHeaders());
        $headers['Ip-Address'] = $request->getClientIp();

        // Snapshot the request origin separately — `host` must not clobber the
        // raw `Host` header (which may carry a port) in the header bag.
        $origin = [
            'scheme'  => $request->getScheme(),
            'host'    => $request->getHost(),
            'port'    => $request->getPort(),
            'baseUrl' => $request->getBaseUrl(),
        ];

        if (Runtime::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            $ctx[self::CTX_KEY]    = $headers;
            $ctx[self::CTX_ORIGIN] = $origin;
        } else {
            self::$bag    = $headers;
            self::$origin = $origin;
        }
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public static function get(string $key): ?string
    {
        return self::storage()[self::normalizeKey($key)] ?? null;
    }

    public static function has(string $key, string $value): bool
    {
        return str_contains(self::get($key) ?? '', $value);
    }

    public static function all(): array
    {
        return self::storage();
    }

    public static function getIpAddress(): ?string
    {
        return self::storage()['Ip-Address']     ?? null;
    }
    public static function getUserAgent(): ?string
    {
        return self::storage()['User-Agent']      ?? null;
    }
    public static function getContentType(): ?string
    {
        return self::storage()['Content-Type']    ?? null;
    }
    public static function getAcceptLanguage(): ?string
    {
        return self::storage()['Accept-Language'] ?? null;
    }
    public static function getOrigin(): ?string
    {
        return self::storage()['Origin']          ?? null;
    }
    public static function getReferer(): ?string
    {
        return self::storage()['Referer']         ?? null;
    }

    // ── Request origin (captured at init) ─────────────────────────────────────

    /** Absolute scheme://host[:port] of the current request, without trailing slash. */
    public static function getBaseUrl(): ?string
    {
        return self::origin()['baseUrl'] ?? null;
    }

    public static function getScheme(): ?string
    {
        return self::origin()['scheme'] ?? null;
    }

    /** Host without port (IPv6 returned bracketed, e.g. "[::1]"). */
    public static function getHost(): ?string
    {
        return self::origin()['host'] ?? null;
    }

    public static function getPort(): ?int
    {
        return self::origin()['port'] ?? null;
    }

    public static function getPreferredLanguage(): ?string
    {
        $al = self::storage()['Accept-Language'] ?? null;
        return $al ? trim(explode(',', $al)[0]) : null;
    }

    public static function isJson(): bool
    {
        return str_contains(self::storage()['Accept'] ?? '', 'application/json')
            || str_contains(self::storage()['Content-Type'] ?? '', 'application/json');
    }

    public static function isAjax(): bool
    {
        return (self::storage()['X-Requested-With'] ?? '') === 'XMLHttpRequest';
    }

    public static function getBearerToken(): ?string
    {
        $auth = self::storage()['Authorization'] ?? '';
        return preg_match('/Bearer\s(\S+)/', $auth, $m) ? $m[1] : null;
    }

    public static function getBasicToken(): ?string
    {
        $auth = self::storage()['Authorization'] ?? '';
        return preg_match('/Basic\s(\S+)/', $auth, $m) ? base64_decode($m[1]) : null;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function storage(): array
    {
        if (Runtime::isSwooleCoroutine()) {
            return \Swoole\Coroutine::getContext()[self::CTX_KEY] ?? [];
        }
        return self::$bag;
    }

    private static function origin(): array
    {
        if (Runtime::isSwooleCoroutine()) {
            return \Swoole\Coroutine::getContext()[self::CTX_ORIGIN] ?? [];
        }
        return self::$origin;
    }

    /** Normalize a single header key to Title-Case. */
    private static function normalizeKey(string $key): string
    {
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($key))));
    }

    /** Normalize all keys in a header map to Title-Case. */
    private static function normalizeMap(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[self::normalizeKey($key)] = $value;
        }
        return $normalized;
    }
}
