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
     * Write the common file-response headers (disposition, cache, length).
     */
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
        $response->header('Cache-Control', 'public, max-age=' . $this->maxAge . ', must-revalidate');
        // Disable compression (Swoole/nginx): Content-Length must match the real body size.
        $response->header('Content-Encoding', 'identity');
        $response->header('Content-Length', (string) $contentLength);

        foreach ($this->extraHeaders as $name => $value) {
            $response->header($name, $value);
        }

        $this->writeCookies($response);
    }
}
