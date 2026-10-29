<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * The interface a server binds, as written in `#[EnableHttp(host:)]`, a WebSocket's
 * `host` or `--<name>.host`: IPv4 (`0.0.0.0`, `127.0.0.1`) or IPv6 (`::`, `::1`).
 *
 * `::` is every interface over both protocols — what an IPv6-only cluster asks an
 * application to listen on. An IPv4 client then arrives as `::ffff:127.0.0.1`.
 *
 * @internal Between the application's servers and what they print.
 */
final class BindAddress
{
    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /**
     * The host as a socket takes it: `[::1]`, written as in a URL, becomes `::1`. Swoole
     * refuses the brackets, and a URL built from them had two pairs.
     */
    public static function bare(string $host): string
    {
        $host = trim($host);
        return str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
    }

    /** Whether the host is an IPv6 address — `::`, `::1`, `fd00::5`. */
    public static function isIpv6(string $host): bool
    {
        return str_contains($host, ':');
    }

    /**
     * The address as a URL writes it: an IPv6 host goes in brackets, or its colons run
     * into the port's — `http://[::]:8000`, not `http://:::8000`.
     */
    public static function url(string $scheme, string $host, int $port): string
    {
        $host = self::bare($host);
        return sprintf('%s://%s:%d', $scheme, self::isIpv6($host) ? "[{$host}]" : $host, $port);
    }
}
