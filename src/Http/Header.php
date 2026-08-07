<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
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
    private const CTX_KEY     = '__k2_headers__';
    private const CTX_ORIGIN  = '__k2_origin__';
    private const CTX_REQUEST = '__k2_request__';

    /**
     * Upper bound on the header-name normalisation memo.
     *
     * Real traffic uses a few dozen distinct names, so this is never reached in practice;
     * it exists because the names are client-controlled. See {@see normalizeKey()}.
     */
    private const int NORMALIZE_MEMO_LIMIT = 256;

    /** FPM fallback storage */
    private static array $bag = [];

    /** FPM fallback storage for the request origin (scheme/host/port/baseUrl). */
    private static array $origin = [];

    /** FPM fallback storage for the request the origin is derived from, on demand. */
    private static ?HttpRequest $request = null;

    private function __construct()
    {
    }

    // ── Initialization ────────────────────────────────────────────────────────

    /** Call once per request in the on('request') handler or index.php entry point. */
    public static function init(HttpRequest $request): void
    {
        $headers = self::normalizeMap($request->getHeaders());
        $headers['Ip-Address'] = $request->getClientIp();

        // The origin (scheme/host/port/baseUrl) is NOT snapshotted here — it is derived
        // on first read by origin(). Nothing in the kernel asks for it, so computing it
        // eagerly charged every request for something only some applications use, and
        // charged it twice over: getBaseUrl() re-derives scheme, host and port itself.
        // The request is kept instead, and the derived values are memoised beside it.
        if (Runtime::isSwooleCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            $ctx[self::CTX_KEY]     = $headers;
            $ctx[self::CTX_REQUEST] = $request;
            unset($ctx[self::CTX_ORIGIN]);
        } else {
            self::$bag     = $headers;
            self::$request = $request;
            self::$origin  = [];
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

    /**
     * The request origin, derived on first read and memoised for the rest of the request.
     *
     * `baseUrl` is assembled from the parts already read rather than through
     * {@see HttpRequest::getBaseUrl()}, which would derive scheme, host and port a
     * second time — the reason the eager version cost twice what it looked like.
     *
     * @return array{scheme?: string, host?: string, port?: int, baseUrl?: string}
     */
    private static function origin(): array
    {
        $swoole = Runtime::isSwooleCoroutine();
        $ctx = $swoole ? \Swoole\Coroutine::getContext() : null;

        $cached = $swoole ? ($ctx[self::CTX_ORIGIN] ?? null) : (self::$origin ?: null);
        if ($cached !== null) {
            return $cached;
        }

        $request = $swoole ? ($ctx[self::CTX_REQUEST] ?? null) : self::$request;
        if (!$request instanceof HttpRequest) {
            return [];
        }

        $scheme = $request->getScheme();
        $host   = $request->getHost();
        $port   = $request->getPort();
        $standard = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        $origin = [
            'scheme'  => $scheme,
            'host'    => $host,
            'port'    => $port,
            'baseUrl' => $standard ? "{$scheme}://{$host}" : "{$scheme}://{$host}:{$port}",
        ];

        if ($swoole) {
            $ctx[self::CTX_ORIGIN] = $origin;
        } else {
            self::$origin = $origin;
        }

        return $origin;
    }

    /**
     * Normalize a single header key to Title-Case.
     *
     * Memoised: the four string operations below run for every key of every request,
     * and real traffic reuses the same few dozen names, so the memo hits almost always.
     *
     * The cap is not a tuning knob — it is the safety property. Header names come from
     * the client, so an uncapped map would let one caller grow a long-lived worker's
     * memory until it dies. Past the cap normalisation simply runs as it did before,
     * which is correct, only not memoised.
     */
    private static function normalizeKey(string $key): string
    {
        static $memo = [];

        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $normalized = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($key))));

        if (count($memo) < self::NORMALIZE_MEMO_LIMIT) {
            $memo[$key] = $normalized;
        }

        return $normalized;
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
