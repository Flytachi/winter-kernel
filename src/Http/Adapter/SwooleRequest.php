<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Swoole\Http\Request;

/**
 * HttpRequest adapter for the Swoole runtime.
 *
 * Usage in server.php:
 *   $server->on('request', function (Request $req, Response $res) use ($router) {
 *       $router->handle(new SwooleRequest($req), new SwooleResponse($res));
 *   });
 */
final class SwooleRequest implements HttpRequest
{
    public function __construct(private readonly Request $request)
    {
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function getUri(): string
    {
        return $this->request->server['request_uri'] ?? '/';
    }

    public function getQueryParams(): array
    {
        return $this->request->get ?? [];
    }

    public function getParsedBody(): array
    {
        return $this->request->post ?? [];
    }

    public function getRawBody(): string
    {
        return $this->request->rawContent() ?: '';
    }

    public function getHeader(string $name): ?string
    {
        return $this->request->header[strtolower($name)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->request->header ?? [];
    }

    public function getUploadedFiles(): array
    {
        return $this->request->files ?? [];
    }

    public function getServerParam(string $key): ?string
    {
        return $this->request->server[$key] ?? null;
    }

    public function getClientIp(): string
    {
        $h = $this->request->header ?? [];

        if (!empty($h['forwarded'])) {
            if (preg_match('/for=["\']?([^;,"\'\s\]]+)/i', $h['forwarded'], $m)) {
                $ip = trim($m[1], '"\'[]');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (!empty($h['x-real-ip'])) {
            $ip = trim($h['x-real-ip']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($h['x-forwarded-for'])) {
            foreach (explode(',', $h['x-forwarded-for']) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->request->server['remote_addr'] ?? '127.0.0.1';
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
        $h = $this->request->header ?? [];
        $tz = $h['timezone'] ?? $h['x-timezone'] ?? null;
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
        // Swoole does not populate a scheme/https flag; SSL detection relies on
        // proxy headers or explicit server configuration upstream.
        return 'http';
    }

    /**
     * Scheme advertised by a trusted proxy via the Forwarded or
     * x-forwarded-proto header, or null when the request is not proxied.
     */
    private function forwardedProto(): ?string
    {
        $h = $this->request->header ?? [];

        if (
            !empty($h['forwarded'])
            && preg_match('/proto=([A-Za-z]+)/i', $h['forwarded'], $m)
        ) {
            $proto = strtolower($m[1]);
            if ($proto === 'http' || $proto === 'https') {
                return $proto;
            }
        }
        if (!empty($h['x-forwarded-proto'])) {
            $proto = strtolower(trim(explode(',', $h['x-forwarded-proto'])[0]));
            if ($proto === 'http' || $proto === 'https') {
                return $proto;
            }
        }
        return null;
    }

    public function getHost(): string
    {
        $host = $this->forwardedHost();
        if ($host !== null) {
            return self::stripPort($host);
        }
        $h = $this->request->header ?? [];
        if (!empty($h['host'])) {
            return self::stripPort((string) $h['host']);
        }
        return 'localhost';
    }

    public function getPort(): int
    {
        $h = $this->request->header ?? [];

        if (!empty($h['x-forwarded-port'])) {
            $port = (int) trim(explode(',', $h['x-forwarded-port'])[0]);
            if ($port > 0) {
                return $port;
            }
        }
        $hostHeader = $this->forwardedHost() ?? ($h['host'] ?? null);
        if ($hostHeader !== null) {
            $port = self::extractPort((string) $hostHeader);
            if ($port !== null) {
                return $port;
            }
        }
        // server_port is the backend's own listener. Port 80 under an https
        // scheme is a contradiction — a TLS-terminating proxy hop or a
        // misconfigured HTTPS flag — that would yield an unreachable
        // https://host:80. Drop that noise and use the scheme default; every
        // other server_port (matching or non-standard) is honoured as-is.
        if (!empty($this->request->server['server_port'])) {
            $serverPort = (int) $this->request->server['server_port'];
            if (!($serverPort === 80 && $this->getScheme() === 'https')) {
                return $serverPort;
            }
        }
        return $this->getScheme() === 'https' ? 443 : 80;
    }

    public function getBaseUrl(): string
    {
        $scheme = $this->getScheme();
        $host   = $this->getHost();
        $port   = $this->getPort();
        $isStandard = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        return $isStandard ? "{$scheme}://{$host}" : "{$scheme}://{$host}:{$port}";
    }

    /** Access the underlying Swoole request when needed (e.g. for file streaming). */
    public function getSwooleRequest(): Request
    {
        return $this->request;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function forwardedHost(): ?string
    {
        $h = $this->request->header ?? [];
        if (!empty($h['forwarded'])) {
            if (preg_match('/host=([^;,\s]+)/i', $h['forwarded'], $m)) {
                return trim($m[1], '"\'');
            }
        }
        if (!empty($h['x-forwarded-host'])) {
            return trim(explode(',', $h['x-forwarded-host'])[0]);
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
