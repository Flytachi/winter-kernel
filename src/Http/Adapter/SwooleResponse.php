<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Adapter;

use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use Swoole\Http\Response;

/**
 * HttpResponse adapter for the Swoole runtime.
 * A thin proxy — Swoole's API matches the interface exactly.
 */
final class SwooleResponse implements HttpResponse
{
    /**
     * @param Response $response underlying Swoole response
     * @param bool $headOnly suppress the body (HEAD request) while keeping headers
     */
    public function __construct(
        private readonly Response $response,
        private readonly bool $headOnly = false,
    ) {
    }

    /** @var list<string> Every Set-Cookie written so far, in order. */
    private array $cookies = [];

    /** @var bool Whether the caller named a Content-Encoding of its own. */
    private bool $encodingDeclared = false;

    public function status(int $code): void
    {
        $this->response->status($code);
    }

    public function header(string $name, string $value): void
    {
        if (strcasecmp($name, 'Content-Encoding') === 0) {
            $this->encodingDeclared = true;
        }

        $this->response->header($name, $value);
    }

    /**
     * Swoole's own cookie() is not used here: it writes `expires=`, `path=` and `secure`
     * in lower case and encodes the value with `+`, none of which FPM would match. The
     * header is built by {@see SetCookie} instead and handed over verbatim.
     *
     * Swoole accepts an array for a repeated header, and a later call replaces the whole
     * set — so the full list is re-sent each time rather than appended to.
     */
    public function cookie(SetCookie $cookie): void
    {
        $this->cookies[] = $cookie->toHeader();
        $this->response->header('Set-Cookie', $this->cookies);
    }

    public function end(string $body = ''): void
    {
        if ($this->headOnly && $body !== '') {
            // HEAD: keep the Content-Length GET would report, drop the body.
            //
            // The encoding line is what makes the length survive. Holding the client's
            // Accept-Encoding — every browser sends one — Swoole compresses the body
            // itself and drops a Content-Length written here, warning ERRNO 7105: the
            // HEAD answer then announces no length at all, while the same handler under
            // FPM announces one. Naming an encoding switches that off, and Swoole reads
            // the headers in the order they were written, so the two lines below cannot
            // be swapped.
            //
            // `identity` is the only honest name for it: the length known here is the
            // unencoded one, and there is no body yet to measure a compressed one on.
            // A caller that encoded the representation itself has already said so, and
            // states the matching length — nothing to overrule.
            if (!$this->encodingDeclared) {
                $this->response->header('Content-Encoding', 'identity');
            }
            $this->response->header('Content-Length', (string) strlen($body));
            $body = '';
        }

        $this->response->end($body);
    }

    public function sendfile(string $path, int $offset = 0, int $length = 0): void
    {
        if ($this->headOnly) {
            // HEAD: Content-Length already set by the caller; send no body.
            $this->response->end('');
            return;
        }

        $this->response->sendfile($path, $offset, $length);
    }
}
