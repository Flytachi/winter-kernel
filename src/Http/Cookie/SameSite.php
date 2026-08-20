<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Cookie;

/**
 * The `SameSite` attribute — when the browser is allowed to attach a cookie to a
 * cross-site request.
 *
 * The attribute is what stands between a session cookie and a CSRF: without it the
 * browser sends the cookie on any request to your origin, including one a third-party
 * page triggered.
 *
 * @link https://winterframe.net/docs/cookies Cookies
 */
enum SameSite: string
{
    /**
     * Sent on same-site requests and on top-level navigations that arrive by GET.
     * The browser default, and the right answer for a session cookie.
     */
    case Lax = 'Lax';

    /**
     * Same-site requests only. A link from another site arrives without the cookie —
     * safest, and visible to the user as "logged out until I click again".
     */
    case Strict = 'Strict';

    /**
     * Sent everywhere, including cross-site. Requires `Secure`; a browser drops the
     * cookie otherwise, which is why {@see SetCookie} refuses to build it without.
     */
    case None = 'None';
}
