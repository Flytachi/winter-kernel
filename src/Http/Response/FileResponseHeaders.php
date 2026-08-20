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
    private function writeFileHeaders(
        HttpResponse $response,
        string $mimeType,
        string $fileName,
        int $contentLength,
    ): void {
        $disposition = $this->isAttachment ? 'attachment' : 'inline';
        $response->header('Content-Type', $mimeType);
        $response->header('Content-Disposition', "{$disposition}; filename=\"{$fileName}\"");
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
