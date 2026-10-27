<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

/**
 * A client's address the way an application expects to read it.
 *
 * A server listening on `::` accepts IPv4 clients too, and sees each of them as an
 * IPv4-mapped IPv6 address: `::ffff:203.0.113.7`. It is the same client, but a log line,
 * a rate limit keyed by IP or an allow-list holding `203.0.113.7` would not recognise it.
 *
 * @internal Shared by the request adapters.
 */
final class ClientAddress
{
    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /** `::ffff:203.0.113.7` → `203.0.113.7`; anything else as it is. */
    public static function unmapped(string $ip): string
    {
        return preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1 ? $m[1] : $ip;
    }
}
