<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;

/**
 * Cookies attached to a response object.
 *
 * Shared by every {@see Sendable} the kernel ships, so which one a handler returns never
 * decides whether it can set a cookie. A JSON payload, a rendered page and a file
 * download all take `->cookie()` the same way.
 *
 * Cookies are held in a list rather than in the `$extraHeaders` map each of those classes
 * already has: that map is keyed by name, and `Set-Cookie` is the one header that
 * legitimately repeats — a second cookie would have replaced the first.
 *
 * @link https://winterframe.net/docs/cookies Cookies
 */
trait CarriesCookies
{
    /** @var list<SetCookie> */
    private array $cookies = [];

    /**
     * Attach a cookie to this response. Call it once per cookie.
     *
     * @param SetCookie $cookie Cookie to send alongside this response.
     */
    public function cookie(SetCookie $cookie): static
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    /** @return list<SetCookie> Cookies attached so far, in the order they were added. */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /** Hands them to the transport, after the headers and before the body. */
    private function writeCookies(HttpResponse $response): void
    {
        foreach ($this->cookies as $cookie) {
            $response->cookie($cookie);
        }
    }
}
