<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Cookie;

/**
 * Turns a raw `Cookie:` request header into a name => value map.
 *
 * Both adapters go through this rather than through the runtime's own parsing, because
 * the two disagree in ways an application would eventually trip over. PHP's `$_COOKIE`
 * rewrites names — a request carrying `my.sid=1; my sid=2; ok=3` arrives as
 * `["my_sid", "ok"]`, the dot renamed and the second cookie gone — while Swoole parses
 * the header itself and keeps them. Reading the header directly is the only way the same
 * request yields the same names under both runtimes.
 *
 * The rest of the behaviour deliberately matches `$_COOKIE`, so nothing surprises anyone
 * arriving from plain PHP: the first of two same-named cookies wins (browsers send the
 * most specific path first), an empty value is kept, and a bare name with no `=` reads
 * as an empty string.
 *
 * @link https://winterframe.net/docs/cookies Cookies
 * @link https://datatracker.ietf.org/doc/html/rfc6265#section-4.2 RFC 6265 — Cookie
 */
final class CookieParser
{
    private function __construct()
    {
    }

    /**
     * @param string $header Raw value of the `Cookie` header.
     * @return array<string, string> Decoded cookies, in the order the client sent them.
     */
    public static function parse(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $split = explode('=', $pair, 2);
            $name  = trim($split[0]);
            if ($name === '' || isset($cookies[$name])) {
                // First occurrence wins: RFC 6265 orders by path specificity, and the
                // most specific is the one this request is about.
                continue;
            }

            // urldecode, not rawurldecode: a cookie set by PHP's own setcookie() encodes
            // a space as `+`, and reading those back is worth the one ambiguity it
            // introduces for a literal `+`.
            $cookies[$name] = urldecode($split[1] ?? '');
        }

        return $cookies;
    }
}
