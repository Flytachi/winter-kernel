<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;

/**
 * Streams a file from disk via HttpResponse::sendfile() (zero-copy on Swoole).
 *
 * Unlike ResponseFile::file(), the file is never loaded fully into memory.
 * Acts as a correct file server: HTTP Range (206), conditional GET (304) and
 * validators (ETag / Last-Modified). HEAD body suppression is handled centrally
 * by the HttpResponse adapter.
 *
 *   ResponseStreamFile::open('/abs/path/video.mp4');
 *
 * Builder flags (via FileResponseHeaders):
 *   ->attachment() / ->inline() / ->maxAge(3600) / ->header($name, $value)
 *   ->acceptRanges(false)   — force atomic full-file delivery, disable Range
 */
final class ResponseStreamFile implements Sendable
{
    use FileResponseHeaders;

    /** Range support is enabled by default — opt-out via acceptRanges(false). */
    private bool $acceptRanges = true;

    private function __construct(
        private string $filePath,
        private string $fileName,
        private string $mimeType,
        bool $isAttachment,
        private HttpCode $httpCode,
        int $maxAge,
    ) {
        $this->isAttachment = $isAttachment;
        $this->maxAge       = $maxAge;
    }

    /**
     * Build a streaming response from an existing file path.
     * Detects MIME type automatically.
     */
    public static function open(
        string $filePath,
        bool $isAttachment = false,
        HttpCode $httpCode = HttpCode::OK,
        int $maxAge = 0,
    ): self {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $fileName = basename($filePath);
        $mime     = mime_content_type($filePath) ?: 'application/octet-stream';

        return new self($filePath, $fileName, $mime, $isAttachment, $httpCode, $maxAge);
    }

    /**
     * Toggle HTTP Range support for this response.
     *
     * Enabled by default (video seeking / resumable downloads). Disable to
     * force atomic full-file delivery (e.g. download counting, one-shot links):
     * the server advertises `Accept-Ranges: none` and ignores any `Range` header.
     */
    public function acceptRanges(bool $enabled = true): static
    {
        $this->acceptRanges = $enabled;
        return $this;
    }

    public function send(HttpResponse $response, HttpRequest $request): void
    {
        $size  = (int) filesize($this->filePath);
        $mtime = (int) filemtime($this->filePath);
        $etag  = sprintf('"%x-%x"', $mtime, $size); // strong validator, like nginx

        // Validators — useful for caching too, always set.
        $response->header('ETag', $etag);
        $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        // Advertise range support per the caller's policy.
        $response->header('Accept-Ranges', $this->acceptRanges ? 'bytes' : 'none');

        // Conditional GET (RFC 9110 §13.2.2): If-None-Match precedes
        // If-Modified-Since; evaluated BEFORE Range. Match → 304 with no body.
        if ($this->httpCode === HttpCode::OK && $this->isNotModified($request, $mtime, $etag)) {
            $response->status(HttpCode::NOT_MODIFIED->value); // validators already set
            $response->end('');
            return;
        }

        // HEAD is not handled here: body suppression is centralized in the adapter
        // (headOnly). send() builds the same status/headers as for GET — the adapter
        // simply omits the body, which also works for a 206/Range response.

        // Range is honoured only when enabled and the base status is 200.
        $range = ($this->acceptRanges && $this->httpCode === HttpCode::OK)
            ? $this->resolveRange($request, $size, $mtime, $etag)
            : null;

        if ($range === false) {
            // Unsatisfiable range → 416.
            $response->status(HttpCode::REQUESTED_RANGE_NOT_SATISFIABLE->value);
            $response->header('Content-Range', "bytes */{$size}");
            $response->end('');
            return;
        }

        if ($range === null) {
            // Full response.
            $response->status($this->httpCode->value);
            $this->writeFileHeaders($response, $this->mimeType, $this->fileName, $size);
            $response->sendfile($this->filePath);
            return;
        }

        // Partial response: 206.
        [$start, $end] = $range;
        $length = $end - $start + 1;
        $response->status(HttpCode::PARTIAL_CONTENT->value);
        $this->writeFileHeaders($response, $this->mimeType, $this->fileName, $length);
        $response->header('Content-Range', "bytes {$start}-{$end}/{$size}");
        $response->sendfile($this->filePath, $start, $length);
    }

    /**
     * Resolve a single-range request against the file size.
     *
     * @return array{0:int,1:int}|null|false
     *         [start,end] for 206, null for full 200, false for 416.
     */
    private function resolveRange(HttpRequest $request, int $size, int $mtime, string $etag): array|null|false
    {
        $header = $request->getHeader('Range');
        if ($header === null || !str_starts_with($header, 'bytes=')) {
            return null; // no range requested
        }

        // If-Range (RFC 9110 §13.1.5): the validator may take two forms —
        // an entity-tag (starts with " or W/) → strong comparison against ETag;
        // otherwise an HTTP-date → comparison against Last-Modified (mtime).
        // No match → the resource changed, send the whole file (200), never stitch.
        $ifRange = $request->getHeader('If-Range');
        if ($ifRange !== null) {
            $isEntityTag = str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/');
            $matches = $isEntityTag
                ? $ifRange === $etag
                : strtotime($ifRange) === $mtime;
            if (!$matches) {
                return null;
            }
        }

        // Multi-range (multipart/byteranges) is unsupported. Per RFC 9110 §14.2
        // the server may ignore Range → send the whole representation (200).
        $set = substr($header, 6);
        if (str_contains($set, ',')) {
            return null;
        }
        [$startRaw, $endRaw] = array_pad(explode('-', $set, 2), 2, '');

        if ($startRaw === '') {
            // bytes=-N → last N bytes
            $n = (int) $endRaw;
            if ($n <= 0) {
                return false;
            }
            $start = max(0, $size - $n);
            $end   = $size - 1;
        } else {
            $start = (int) $startRaw;
            $end   = $endRaw === '' ? $size - 1 : (int) $endRaw;
        }

        $end = min($end, $size - 1);
        if ($start > $end || $start >= $size) {
            return false; // unsatisfiable range → 416
        }

        return [$start, $end];
    }

    /**
     * Evaluate conditional-GET preconditions (RFC 9110 §13.2.2).
     * If-None-Match takes precedence; If-Modified-Since used only in its absence.
     */
    private function isNotModified(HttpRequest $request, int $mtime, string $etag): bool
    {
        $inm = $request->getHeader('If-None-Match');
        if ($inm !== null) {
            return $inm === '*' || $this->etagMatches($etag, $inm);
        }

        $ims = $request->getHeader('If-Modified-Since');
        $imsTime = $ims !== null ? strtotime($ims) : false;
        return $imsTime !== false && $mtime <= $imsTime;
    }

    /**
     * Weak comparison of our ETag against an If-None-Match list (RFC 9110 §13.1.2).
     * The optional `W/` prefix is ignored on both sides.
     */
    private function etagMatches(string $etag, string $header): bool
    {
        $strip = static fn(string $t): string => str_starts_with($t, 'W/') ? substr($t, 2) : $t;
        $target = $strip($etag);
        return array_any(
            explode(',', $header),
            fn(string $candidate) => $strip(trim($candidate)) === $target
        );
    }
}
