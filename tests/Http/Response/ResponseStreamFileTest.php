<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Response\ResponseStreamFile;
use PHPUnit\Framework\TestCase;

// ── Spy HttpResponse — records everything send() does ─────────────────────────

final class SpyResponse implements HttpResponse
{
    public ?int $statusCode = null;
    public array $headers = [];
    public ?string $endBody = null;
    /** @var array{0:string,1:int,2:int}|null */
    public ?array $sentFile = null;

    public function status(int $code): void
    {
        $this->statusCode = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function end(string $body = ''): void
    {
        $this->endBody = $body;
    }

    public function sendfile(string $path, int $offset = 0, int $length = 0): void
    {
        $this->sentFile = [$path, $offset, $length];
    }
}

// ── Stub HttpRequest — configurable method + headers ──────────────────────────

final class StubRequest implements HttpRequest
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $method = 'GET',
        private array $headers = [],
    ) {
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return '/';
    }

    public function getQueryParams(): array
    {
        return [];
    }

    public function getParsedBody(): array
    {
        return [];
    }

    public function getRawBody(): string
    {
        return '';
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function getServerParam(string $key): ?string
    {
        return null;
    }

    public function getClientIp(): string
    {
        return '127.0.0.1';
    }

    public function getClientTimezone(): ?string
    {
        return null;
    }

    public function getScheme(): string
    {
        return 'http';
    }

    public function getHost(): string
    {
        return 'localhost';
    }

    public function getPort(): int
    {
        return 80;
    }

    public function getBaseUrl(): string
    {
        return 'http://localhost';
    }
}

final class ResponseStreamFileTest extends TestCase
{
    private const SIZE = 1000;

    private string $path;
    private int $mtime;
    private string $etag;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/rsf_' . uniqid() . '.txt';
        file_put_contents($this->path, str_repeat('A', self::SIZE));
        $this->mtime = (int) filemtime($this->path);
        $this->etag  = sprintf('"%x-%x"', $this->mtime, self::SIZE);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function send(ResponseStreamFile $r, StubRequest $req): SpyResponse
    {
        $res = new SpyResponse();
        $r->send($res, $req);
        return $res;
    }

    // ── open() ────────────────────────────────────────────────────────────────

    public function testOpenThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        ResponseStreamFile::open('/no/such/file.bin');
    }

    // ── full response ───────────────────────────────────────────────────────────

    public function testFullResponseSetsHeadersAndStreams(): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path), new StubRequest());

        self::assertSame(HttpCode::OK->value, $res->statusCode);
        self::assertSame((string) self::SIZE, $res->headers['Content-Length']);
        self::assertSame('bytes', $res->headers['Accept-Ranges']);
        self::assertSame('identity', $res->headers['Content-Encoding']);
        self::assertSame($this->etag, $res->headers['ETag']);
        self::assertArrayHasKey('Last-Modified', $res->headers);
        self::assertStringStartsWith('inline; filename=', $res->headers['Content-Disposition']);
        self::assertSame([$this->path, 0, 0], $res->sentFile);
        self::assertNull($res->endBody); // streamed, not end()
    }

    public function testAttachmentDisposition(): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path)->attachment(), new StubRequest());
        self::assertStringStartsWith('attachment; filename=', $res->headers['Content-Disposition']);
    }

    // ── partial (206) ───────────────────────────────────────────────────────────

    public function testPartialRange(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=0-99']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);

        self::assertSame(HttpCode::PARTIAL_CONTENT->value, $res->statusCode);
        self::assertSame('bytes 0-99/1000', $res->headers['Content-Range']);
        self::assertSame('100', $res->headers['Content-Length']);
        self::assertSame([$this->path, 0, 100], $res->sentFile);
    }

    public function testSuffixRange(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=-500']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);

        self::assertSame(HttpCode::PARTIAL_CONTENT->value, $res->statusCode);
        self::assertSame('bytes 500-999/1000', $res->headers['Content-Range']);
        self::assertSame([$this->path, 500, 500], $res->sentFile);
    }

    public function testOpenEndedRange(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=900-']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);

        self::assertSame(HttpCode::PARTIAL_CONTENT->value, $res->statusCode);
        self::assertSame('bytes 900-999/1000', $res->headers['Content-Range']);
        self::assertSame([$this->path, 900, 100], $res->sentFile);
    }

    // ── 416 ─────────────────────────────────────────────────────────────────────

    public function testUnsatisfiableRange(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=99999-']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);

        self::assertSame(HttpCode::REQUESTED_RANGE_NOT_SATISFIABLE->value, $res->statusCode);
        self::assertSame('bytes */1000', $res->headers['Content-Range']);
        self::assertSame('', $res->endBody);
        self::assertNull($res->sentFile);
    }

    // ── multi-range → full 200 ────────────────────────────────────────────────────

    public function testMultiRangeFallsBackToFull(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=0-99,200-299']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);

        self::assertSame(HttpCode::OK->value, $res->statusCode);
        self::assertSame([$this->path, 0, 0], $res->sentFile);
    }

    // ── If-Range ──────────────────────────────────────────────────────────────────

    public function testIfRangeEntityTagMatch(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=0-99', 'If-Range' => $this->etag]);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::PARTIAL_CONTENT->value, $res->statusCode);
    }

    public function testIfRangeEntityTagMismatchFull(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=0-99', 'If-Range' => '"deadbeef"']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::OK->value, $res->statusCode);
        self::assertSame([$this->path, 0, 0], $res->sentFile);
    }

    public function testIfRangeDateMatch(): void
    {
        $date = gmdate('D, d M Y H:i:s', $this->mtime) . ' GMT';
        $req  = new StubRequest('GET', ['Range' => 'bytes=0-99', 'If-Range' => $date]);
        $res  = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::PARTIAL_CONTENT->value, $res->statusCode);
    }

    // ── acceptRanges(false) ───────────────────────────────────────────────────────

    public function testAcceptRangesDisabled(): void
    {
        $req = new StubRequest('GET', ['Range' => 'bytes=0-99']);
        $res = $this->send(ResponseStreamFile::open($this->path)->acceptRanges(false), $req);

        self::assertSame(HttpCode::OK->value, $res->statusCode);
        self::assertSame('none', $res->headers['Accept-Ranges']);
        self::assertSame([$this->path, 0, 0], $res->sentFile);
    }

    // ── conditional GET (304) ──────────────────────────────────────────────────────

    public function testIfNoneMatchReturns304(): void
    {
        $req = new StubRequest('GET', ['If-None-Match' => $this->etag]);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);

        self::assertSame(HttpCode::NOT_MODIFIED->value, $res->statusCode);
        self::assertSame('', $res->endBody);
        self::assertNull($res->sentFile);
    }

    public function testIfNoneMatchWildcardReturns304(): void
    {
        $req = new StubRequest('GET', ['If-None-Match' => '*']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::NOT_MODIFIED->value, $res->statusCode);
    }

    public function testIfNoneMatchMismatchStreams(): void
    {
        $req = new StubRequest('GET', ['If-None-Match' => '"other"']);
        $res = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::OK->value, $res->statusCode);
        self::assertSame([$this->path, 0, 0], $res->sentFile);
    }

    public function testIfModifiedSinceReturns304(): void
    {
        $date = gmdate('D, d M Y H:i:s', $this->mtime + 10) . ' GMT';
        $req  = new StubRequest('GET', ['If-Modified-Since' => $date]);
        $res  = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::NOT_MODIFIED->value, $res->statusCode);
    }

    public function testIfModifiedSinceOlderStreams(): void
    {
        $date = gmdate('D, d M Y H:i:s', $this->mtime - 10) . ' GMT';
        $req  = new StubRequest('GET', ['If-Modified-Since' => $date]);
        $res  = $this->send(ResponseStreamFile::open($this->path), $req);
        self::assertSame(HttpCode::OK->value, $res->statusCode);
    }
}