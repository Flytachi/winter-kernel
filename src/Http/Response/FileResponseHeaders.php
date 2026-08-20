<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;

/**
 * Shared builder + header logic for file-style responses.
 *
 * Used by ResponseFile (in-memory body) and ResponseStreamFile (disk file).
 */
trait FileResponseHeaders
{
    use CarriesCookies;

    private array $extraHeaders = [];
    private bool $isAttachment;
    private int $maxAge;

    /** Content sniffing is off by default; see {@see sniffable()}. */
    private bool $sniffable = false;

    /** Force Content-Disposition: attachment (download dialog). */
    public function attachment(): static
    {
        $this->isAttachment = true;
        return $this;
    }

    /** Force Content-Disposition: inline (render in browser). */
    public function inline(): static
    {
        $this->isAttachment = false;
        return $this;
    }

    /** Set Cache-Control max-age in seconds. */
    public function maxAge(int $seconds): static
    {
        $this->maxAge = $seconds;
        return $this;
    }

    /** Add or replace an extra response header. */
    public function header(string $name, string $value): static
    {
        $this->extraHeaders[$name] = $value;
        return $this;
    }

    /**
     * Lets the browser sniff the content type instead of trusting the declared one.
     *
     * Off by default, and the default is the safe one. Turn it on only for content you
     * produced yourself and whose type you would rather the browser guessed — there is
     * no way to remove the header afterwards, since {@see header()} can overwrite a
     * header but not delete it.
     *
     * @param bool $allow True lets the browser sniff; false restores `nosniff`.
     */
    public function sniffable(bool $allow = true): static
    {
        $this->sniffable = $allow;

        return $this;
    }

    /**
     * A `Content-Disposition` a browser can actually parse — RFC 6266 §4.3.
     *
     * The name reaching this method is frequently not ours: `ResponseStreamFile::open()`
     * takes `basename()` of the path, and in most applications that path was built from
     * an upload. Interpolating it straight into a quoted-string breaks on the first
     * quote — `отчёт "Q3".pdf` closes the string early and the browser reads the rest as
     * malformed parameters — and raw non-ASCII bytes are not allowed in a header field
     * at all, so the file lands under a mangled name.
     *
     * Hence the pair the RFC prescribes: an ASCII-only `filename` every client
     * understands, and `filename*` carrying the real name percent-encoded as UTF-8.
     *
     * @param string $disposition `attachment` or `inline`.
     * @param string $fileName The name to offer the client, as-is.
     */
    private static function contentDisposition(string $disposition, string $fileName): string
    {
        // Anything outside printable ASCII becomes an underscore, quotes and backslashes
        // included — a control character would otherwise let the name inject a header.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $fileName) ?? '';
        $ascii = str_replace(['"', '\\'], '_', $ascii);
        if (trim($ascii, '_ ') === '') {
            $ascii = 'file';
        }

        return sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $disposition,
            $ascii,
            rawurlencode($fileName),
        );
    }

    /**
     * Headers that describe the resource and the caller's policy, not the bytes.
     *
     * Written before the 304 and 416 decisions, because they are true whatever the status
     * turns out to be — and because a 304 that omits them silently keeps the client on
     * stale ones. RFC 9111 §4.3.4: a cache updates its stored response with the fields a
     * 304 carries, and a field that is absent simply keeps its old value. So a policy
     * changed from `public` to `no-store` would never reach a client that already holds a
     * copy, and a link whose `max-age` counts down from the remaining lifetime would have
     * the client keep the first value it ever saw — outliving the link itself.
     *
     * Cookies belong here for a plainer reason: the caller asked for one. Dropping it on
     * revalidation, where nothing announces the loss, is the kind of behaviour that gets
     * found months later.
     */
    private function writeResourceHeaders(HttpResponse $response): void
    {
        $response->header('Cache-Control', 'public, max-age=' . $this->maxAge . ', must-revalidate');

        foreach ($this->extraHeaders as $name => $value) {
            $response->header($name, $value);
        }

        $this->writeCookies($response);
    }

    /**
     * Headers that describe the representation being sent — written only when a body
     * follows, since none of them mean anything on a 304 or a 416.
     */
    private function writeFileHeaders(
        HttpResponse $response,
        string $mimeType,
        string $fileName,
        int $contentLength,
    ): void {
        $disposition = $this->isAttachment ? 'attachment' : 'inline';
        $response->header('Content-Type', $mimeType);
        $response->header('Content-Disposition', self::contentDisposition($disposition, $fileName));

        if (!$this->sniffable) {
            // The file usually came from a user, and this class always states an explicit
            // Content-Type — so there is nothing a sniffing browser could usefully add,
            // and plenty it could get wrong: a "text" upload read as HTML is an XSS.
            $response->header('X-Content-Type-Options', 'nosniff');
        }
        // Disable compression (Swoole/nginx): Content-Length must match the real body size.
        $response->header('Content-Encoding', 'identity');
        $response->header('Content-Length', (string) $contentLength);

        // Applied a second time on purpose: the map is idempotent, and re-applying it
        // after the content headers keeps ->header('Content-Type', …) working as the
        // override it reads as.
        foreach ($this->extraHeaders as $name => $value) {
            $response->header($name, $value);
        }
    }
}
