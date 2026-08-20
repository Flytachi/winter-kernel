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
 *
 * @link https://winterframe.net/docs/responses Streaming, Range requests and conditional 304
 */
final class ResponseStreamFile implements Sendable
{
    use FileResponseHeaders;

    /** Range support is enabled by default — opt-out via acceptRanges(false). */
    private bool $acceptRanges = true;

    /** Resolved on first use when the caller did not state one; see {@see contentType()}. */
    private ?string $mimeType = null;

    /** @var (\Closure(int): void)|null Called before a representation is written. */
    private ?\Closure $beforeSend = null;

    private function __construct(
        private string $filePath,
        private string $fileName,
        ?string $mimeType,
        bool $isAttachment,
        private HttpCode $httpCode,
        int $maxAge,
    ) {
        $this->mimeType     = $mimeType;
        $this->isAttachment = $isAttachment;
        $this->maxAge       = $maxAge;
    }

    /**
     * Build a streaming response from an existing file path.
     *
     * The name shown to the client defaults to the file's own, and the content type is
     * detected from its contents — but only when {@see contentType()} was not used, and
     * only at send time. `mime_content_type()` opens the file and reads its magic header,
     * which costs about 0.9 ms on a 2 MB file and is pure waste when the caller already
     * knows the type.
     *
     * The existence check stays here: failing while the response is being built is a
     * better place than failing halfway through writing it.
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

        return new self($filePath, basename($filePath), null, $isAttachment, $httpCode, $maxAge);
    }

    /**
     * The name offered to the client, when it differs from the name on disk.
     *
     * Content-addressed storage is the case this exists for: the file is a hash with no
     * extension, and the name worth showing lives in a database.
     *
     * @param string $name Sent as-is; encoding for the header is handled downstream.
     */
    public function fileName(string $name): static
    {
        $this->fileName = $name;

        return $this;
    }

    /**
     * The content type, when the caller already knows it.
     *
     * Skips detection entirely — see {@see open()} for what that saves.
     *
     * @param string $mime A media type, e.g. `application/pdf`.
     */
    public function contentType(string $mime): static
    {
        $this->mimeType = $mime;

        return $this;
    }

    /**
     * Runs just before a representation is written to the response.
     *
     * Not called on 304 (revalidation transfers nothing), on 416 (the range was refused)
     * or on HEAD (the adapter drops the body, so the client sees no bytes). The argument
     * is the length the `Content-Length` will announce — for a 206 that is the size of
     * the part, not of the file.
     *
     * It runs after the 304/416 decisions and before any body header is written, so an
     * exception thrown from it cancels delivery. That is deliberate: if the download
     * could not be recorded, the file should not go out.
     *
     * **It reports an intent to send, not a delivery.** `sendfile()` hands the file to
     * the reactor and the client can still disappear mid-transfer, with nothing reported
     * back to PHP. A counter built on this counts downloads *started*. There is no honest
     * way to count the other thing from here, which is worth knowing before a one-shot
     * link or an egress bill is built on it.
     *
     * @param (\Closure(int $bytes): void)|null $hook Null removes a previously set hook.
     */
    public function beforeSend(?\Closure $hook): static
    {
        $this->beforeSend = $hook;

        return $this;
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
        // PHP's stat cache lives for the life of the process, not of the request. A Swoole
        // worker serves for hours, so a file replaced from outside would still report its
        // former size here — and sendfile() would then send the new bytes, leaving the
        // response shorter or longer than the Content-Length announced. The validators
        // would go stale the same way, answering 304 for content that had changed.
        // Verified: without the reset, filesize() kept returning 1000 for a file another
        // process had grown to 5000.
        clearstatcache(true, $this->filePath);

        // One syscall for both values rather than two.
        $stat  = stat($this->filePath);
        $size  = (int) ($stat['size'] ?? 0);
        $mtime = (int) ($stat['mtime'] ?? 0);
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

        // Everything below writes a representation, so this is where the hook belongs:
        // past 304 and 416, before a single body header.
        $this->announce($request, $range === null ? $size : $range[1] - $range[0] + 1);

        if ($range === null) {
            // Full response.
            $response->status($this->httpCode->value);
            $this->writeFileHeaders($response, $this->contentTypeOf(), $this->fileName, $size);
            $response->sendfile($this->filePath);
            return;
        }

        // Partial response: 206.
        [$start, $end] = $range;
        $length = $end - $start + 1;
        $response->status(HttpCode::PARTIAL_CONTENT->value);
        $this->writeFileHeaders($response, $this->contentTypeOf(), $this->fileName, $length);
        $response->header('Content-Range', "bytes {$start}-{$end}/{$size}");
        $response->sendfile($this->filePath, $start, $length);
    }

    /**
     * Calls the hook, unless this request will carry no bytes.
     *
     * HEAD is the trap worth naming: the router runs it through the GET handler and the
     * adapter drops the body afterwards, so a counter written here would happily count a
     * request the client never received a byte of.
     *
     * @param int $bytes Length the response will announce.
     */
    private function announce(HttpRequest $request, int $bytes): void
    {
        if ($this->beforeSend === null || strtoupper($request->getMethod()) === 'HEAD') {
            return;
        }

        ($this->beforeSend)($bytes);
    }

    /** The declared type, or one read from the file's contents on first use. */
    private function contentTypeOf(): string
    {
        return $this->mimeType ??= mime_content_type($this->filePath) ?: 'application/octet-stream';
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
