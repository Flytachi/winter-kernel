<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Cookie\CookieParser;

/**
 * HttpRequest adapter for PHP-FPM / Apache (CGI model).
 * Reads from superglobals — safe for single-request process lifecycle.
 *
 * Usage in index.php:
 *   $router->handle(new FpmRequest(), new FpmResponse());
 */
final class FpmRequest implements HttpRequest
{
    private readonly array $headers;

    /** @var array<string, string>|null Parsed on first use. */
    private ?array $cookies = null;
    private ?string $rawBody = null;

    public function __construct()
    {
        $this->headers = $this->extractHeaders();
    }

    public function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function getUri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public function getQueryParams(): array
    {
        return $_GET;
    }

    public function getParsedBody(): array
    {
        return $_POST;
    }

    public function getRawBody(): string
    {
        return $this->rawBody ??= (file_get_contents('php://input') ?: '');
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getCookie(string $name): ?string
    {
        return $this->getCookies()[$name] ?? null;
    }

    /**
     * Parsed from the raw header, not taken from `$_COOKIE`: PHP rewrites cookie names
     * there — `my.sid` becomes `my_sid`, and a name with a space disappears — which
     * Swoole does not do. See {@see CookieParser}.
     *
     * Memoised: the header does not change during a request, and getCookie() would
     * otherwise re-parse it on every lookup.
     */
    public function getCookies(): array
    {
        return $this->cookies ??= CookieParser::parse($this->headers['cookie'] ?? '');
    }

    public function getUploadedFiles(): array
    {
        $result = [];
        foreach ($_FILES as $field => $info) {
            if (is_array($info['name'])) {
                foreach (array_keys($info['name']) as $i) {
                    $result[$field][] = [
                        'name'     => $info['name'][$i],
                        'type'     => $info['type'][$i],
                        'tmp_name' => $info['tmp_name'][$i],
                        'error'    => $info['error'][$i],
                        'size'     => $info['size'][$i],
                    ];
                }
            } else {
                $result[$field] = $info;
            }
        }
        return $result;
    }

    public function getServerParam(string $key): string|int|float|null
    {
        return $_SERVER[$key] ?? null;
    }

    public function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_FORWARDED'])) {
            if (preg_match('/for=["\']?([^;,"\'\s\]]+)/i', $_SERVER['HTTP_FORWARDED'], $m)) {
                $ip = trim($m[1], '"\'[]');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Client-supplied IANA timezone (e.g. 'Asia/Tokyo') from the
     * `Timezone` or `X-Timezone` header, validated against
     * timezone_identifiers_list(). Returns null if absent or unknown.
     *
     * The framework does NOT mutate date_default_timezone_get() based on
     * this value. Apply ClientTimezoneMiddleware to opt into global
     * mutation, or use it explicitly:
     *   $tz = new DateTimeZone($req->getClientTimezone() ?? env('TIME_ZONE', 'UTC'));
     */
    public function getClientTimezone(): ?string
    {
        $tz = $this->headers['timezone'] ?? $this->headers['x-timezone'] ?? null;
        if ($tz === null) {
            return null;
        }
        return in_array($tz, timezone_identifiers_list(), true) ? $tz : null;
    }

    public function getScheme(): string
    {
        $proto = $this->forwardedProto();
        if ($proto !== null) {
            return $proto;
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return 'https';
        }
        if (!empty($_SERVER['REQUEST_SCHEME'])) {
            $proto = strtolower((string) $_SERVER['REQUEST_SCHEME']);
            if ($proto === 'http' || $proto === 'https') {
                return $proto;
            }
        }
        return 'http';
    }

    public function getHost(): string
    {
        $host = $this->forwardedHost();
        if ($host !== null) {
            return self::stripPort($host);
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            return self::stripPort((string) $_SERVER['HTTP_HOST']);
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            return (string) $_SERVER['SERVER_NAME'];
        }
        return 'localhost';
    }

    public function getPort(): int
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
            $port = (int) trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PORT'])[0]);
            if ($port > 0) {
                return $port;
            }
        }
        $hostHeader = $this->forwardedHost() ?? ($_SERVER['HTTP_HOST'] ?? null);
        if ($hostHeader !== null) {
            $port = self::extractPort((string) $hostHeader);
            if ($port !== null) {
                return $port;
            }
        }
        // SERVER_PORT is the backend's own listener. Port 80 under an https
        // scheme is a contradiction — a TLS-terminating proxy hop or a
        // misconfigured HTTPS flag — that would yield an unreachable
        // https://host:80. Drop that noise and use the scheme default; every
        // other SERVER_PORT (matching or non-standard) is honoured as-is.
        if (!empty($_SERVER['SERVER_PORT'])) {
            $serverPort = (int) $_SERVER['SERVER_PORT'];
            if (!($serverPort === 80 && $this->getScheme() === 'https')) {
                return $serverPort;
            }
        }
        return $this->getScheme() === 'https' ? 443 : 80;
    }

    /**
     * Scheme advertised by a trusted proxy via the Forwarded or
     * X-Forwarded-Proto header, or null when the request is not proxied.
     */
    private function forwardedProto(): ?string
    {
        if (
            !empty($_SERVER['HTTP_FORWARDED'])
            && preg_match('/proto=([A-Za-z]+)/i', $_SERVER['HTTP_FORWARDED'], $m)
        ) {
            $proto = strtolower($m[1]);
            if ($proto === 'http' || $proto === 'https') {
                return $proto;
            }
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
            if ($proto === 'http' || $proto === 'https') {
                return $proto;
            }
        }
        return null;
    }

    public function getBaseUrl(): string
    {
        $scheme = $this->getScheme();
        $host   = $this->getHost();
        $port   = $this->getPort();
        $isStandard = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        return $isStandard ? "{$scheme}://{$host}" : "{$scheme}://{$host}:{$port}";
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function extractHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
            return $headers;
        }

        // Fallback: extract from $_SERVER HTTP_* keys
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    private function forwardedHost(): ?string
    {
        if (!empty($_SERVER['HTTP_FORWARDED'])) {
            if (preg_match('/host=([^;,\s]+)/i', $_SERVER['HTTP_FORWARDED'], $m)) {
                return trim($m[1], '"\'');
            }
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        return null;
    }

    private static function stripPort(string $host): string
    {
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end !== false ? substr($host, 0, $end + 1) : $host;
        }
        $colon = strrpos($host, ':');
        return $colon !== false ? substr($host, 0, $colon) : $host;
    }

    private static function extractPort(string $host): ?int
    {
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end !== false && isset($host[$end + 1]) && $host[$end + 1] === ':') {
                $port = (int) substr($host, $end + 2);
                return $port > 0 ? $port : null;
            }
            return null;
        }
        $colon = strrpos($host, ':');
        if ($colon === false) {
            return null;
        }
        $port = (int) substr($host, $colon + 1);
        return $port > 0 ? $port : null;
    }
}
