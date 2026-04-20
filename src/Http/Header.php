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
 *
 * Swoole: stores in Coroutine::getContext() — coroutine-safe, per-request isolation.
 * FPM:    stores in a static array — process-wide, safe for single-request lifecycle.
 */
final class Header
{
    private const CTX_KEY = '__k2_headers__';

    /** FPM fallback storage */
    private static array $bag = [];

    private function __construct()
    {
    }

    // ── Initialization ────────────────────────────────────────────────────────

    /** Call once per request in the on('request') handler or index.php entry point. */
    public static function init(HttpRequest $request): void
    {
        $headers = self::normalizeMap($request->getHeaders());
        $headers['Ip-Address'] = $request->getClientIp();

        if (Runtime::isSwooleCoroutine()) {
            \Swoole\Coroutine::getContext()[self::CTX_KEY] = $headers;
        } else {
            self::$bag = $headers;
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
