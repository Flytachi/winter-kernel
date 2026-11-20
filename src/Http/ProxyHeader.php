<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

/**
 * A family of headers a trusted proxy tells the client with. Only the families the
 * application names are read: a proxy passes on what it does not set itself — nginx
 * appends `X-Forwarded-For` and forwards a client's own `Forwarded` and `X-Real-IP`
 * untouched — so a family the proxy does not write is the client's word.
 *
 * @link https://winterframe.net/docs/web-configuration Trusted proxies
 */
enum ProxyHeader: string
{
    /** `X-Forwarded-For`, `-Proto`, `-Host`, `-Port` — what nginx, HAProxy, ingresses set. The default. */
    case XForwarded = 'x-forwarded';

    /** `X-Real-IP` — only when the proxy overwrites it (`proxy_set_header X-Real-IP $remote_addr`). */
    case XRealIp = 'x-real-ip';

    /** `Forwarded` (RFC 7239) — only when the proxy writes it. */
    case Forwarded = 'forwarded';
}
