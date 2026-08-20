<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Response;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Cookie\SetCookie;
use Flytachi\Winter\Kernel\Http\Response\ResponseStreamFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// ── Spy HttpResponse — records everything send() does ─────────────────────────

final class SpyResponse implements HttpResponse
{
    /** @var list<string> */
    public array $cookies = [];

    public ?int $statusCode = null;
    public array $headers = [];
    public ?string $endBody = null;
    /** @var array{0:string,1:int,2:int}|null */
    public ?array $sentFile = null;

    public function status(int $code): void
    {
        $this->statusCode = $code;
    }

    public function cookie(\Flytachi\Winter\Kernel\Http\Cookie\SetCookie $cookie): void
    {
        $this->cookies[] = $cookie->toHeader();
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

    public function getCookie(string $name): ?string
    {
        return null;
    }

    public function getCookies(): array
    {
        return [];
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

    // ── Content-Disposition, RFC 6266 §4.3 ───────────────────────────────────

    /**
     * The name is rarely ours: open() takes basename() of a path, and in most
     * applications that path came from an upload. Interpolated straight into a
     * quoted-string, the first quote closes it early and the browser reads the rest as
     * malformed parameters.
     */
    public function test_a_quote_in_the_name_cannot_break_the_header(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->fileName('report "Q3"; rm -rf.pdf')->attachment(),
            new StubRequest(),
        );

        $header = $res->headers['Content-Disposition'];

        self::assertSame(
            1,
            preg_match('/filename="([^"]*)"/', $header, $m),
            'the quoted-string has to parse',
        );
        // The name's own semicolon lives inside the quotes, which is legal; a quote does
        // not, and that is what used to end the string early.
        self::assertSame('report _Q3_; rm -rf.pdf', $m[1]);
        self::assertStringContainsString("filename*=UTF-8''", $header, 'the real name still travels');
    }

    public function test_a_non_ascii_name_travels_in_filename_star(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->fileName('отчёт.pdf')->attachment(),
            new StubRequest(),
        );

        $header = $res->headers['Content-Disposition'];

        self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('отчёт.pdf'), $header);
        self::assertStringContainsString('filename="', $header, 'an ASCII fallback stays for old clients');
        self::assertSame(
            $header,
            preg_replace('/[^\x20-\x7E]/', '', $header),
            'no raw non-ASCII byte reaches the header field',
        );
    }

    /** A newline in the name would otherwise let it inject a header of its own. */
    public function test_a_control_character_in_the_name_is_neutralised(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->fileName("a\r\nX-Injected: 1")->attachment(),
            new StubRequest(),
        );

        $header = $res->headers['Content-Disposition'];

        // The property that matters is that no line break survives: the text may well
        // remain inside the quoted filename, where it is just a name.
        self::assertSame($header, str_replace(["\r", "\n"], '', $header), 'no line break reaches the field');
        self::assertMatchesRegularExpression('/filename="a__X-Injected: 1"/', $header);
    }

    public function test_a_name_of_only_non_ascii_still_leaves_a_usable_fallback(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->fileName('отчёт')->attachment(),
            new StubRequest(),
        );

        self::assertStringContainsString('filename="file"', $res->headers['Content-Disposition']);
    }

    // ── Content sniffing ──────────────────────────────────────────────────────

    /** The class serves user uploads and always states a type, so sniffing can only hurt. */
    public function test_sniffing_is_refused_by_default(): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path), new StubRequest());

        self::assertSame('nosniff', $res->headers['X-Content-Type-Options']);
    }

    public function test_sniffing_can_be_allowed_explicitly(): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path)->sniffable(), new StubRequest());

        self::assertArrayNotHasKey('X-Content-Type-Options', $res->headers);
    }

    // ── Name and type stated by the caller ────────────────────────────────────

    /** Content-addressed storage: a hash on disk, a real name in the database. */
    public function test_the_caller_can_state_name_and_type(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->fileName('invoice.pdf')->contentType('application/pdf'),
            new StubRequest(),
        );

        self::assertSame('application/pdf', $res->headers['Content-Type']);
        self::assertStringContainsString('filename="invoice.pdf"', $res->headers['Content-Disposition']);
    }

    public function test_without_a_stated_type_it_is_detected(): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path), new StubRequest());

        self::assertNotSame('', $res->headers['Content-Type']);
    }

    // ── beforeSend ────────────────────────────────────────────────────────────

    public function test_the_hook_reports_the_full_size(): void
    {
        $seen = [];
        $this->send(
            ResponseStreamFile::open($this->path)->beforeSend(function (int $b) use (&$seen): void {
                $seen[] = $b;
            }),
            new StubRequest(),
        );

        self::assertSame([self::SIZE], $seen);
    }

    /** For a 206 the number is the size of the part, not of the file. */
    public function test_the_hook_reports_the_length_of_a_range(): void
    {
        $seen = [];
        $this->send(
            ResponseStreamFile::open($this->path)->beforeSend(function (int $b) use (&$seen): void {
                $seen[] = $b;
            }),
            new StubRequest('GET', ['Range' => 'bytes=0-9']),
        );

        self::assertSame([10], $seen);
    }

    public function test_the_hook_is_silent_on_304(): void
    {
        $called = false;
        $res = $this->send(
            ResponseStreamFile::open($this->path)->beforeSend(function () use (&$called): void {
                $called = true;
            }),
            new StubRequest('GET', ['If-None-Match' => $this->etag]),
        );

        self::assertSame(HttpCode::NOT_MODIFIED->value, $res->statusCode);
        self::assertFalse($called, 'revalidation transfers nothing');
    }

    public function test_the_hook_is_silent_on_416(): void
    {
        $called = false;
        $res = $this->send(
            ResponseStreamFile::open($this->path)->beforeSend(function () use (&$called): void {
                $called = true;
            }),
            new StubRequest('GET', ['Range' => 'bytes=999999-']),
        );

        self::assertSame(HttpCode::REQUESTED_RANGE_NOT_SATISFIABLE->value, $res->statusCode);
        self::assertFalse($called);
    }

    /**
     * The trap this hook exists to keep people out of: the router runs HEAD through the
     * GET handler and the adapter drops the body afterwards, so a counter written by hand
     * would count a request the client never received a byte of.
     */
    public function test_the_hook_is_silent_on_head(): void
    {
        $called = false;
        $this->send(
            ResponseStreamFile::open($this->path)->beforeSend(function () use (&$called): void {
                $called = true;
            }),
            new StubRequest('HEAD'),
        );

        self::assertFalse($called);
    }

    /** If the download could not be recorded, the file must not go out. */
    public function test_an_exception_from_the_hook_cancels_delivery(): void
    {
        $res = new SpyResponse();

        try {
            ResponseStreamFile::open($this->path)
                ->beforeSend(fn() => throw new \RuntimeException('quota exhausted'))
                ->send($res, new StubRequest());
            self::fail('delivery should have been cancelled');
        } catch (\RuntimeException $e) {
            self::assertSame('quota exhausted', $e->getMessage());
        }

        self::assertNull($res->sentFile, 'nothing was streamed');
        self::assertArrayNotHasKey('Content-Length', $res->headers, 'no body header was written');
    }

    // ── Invalid Range is ignored; unsatisfiable Range is refused ──────────────

    /**
     * RFC 9110 keeps these two apart, and so must the response. §14.1.1: "the recipient
     * MUST ignore an invalid byte-range-spec" — the request then reads as if no Range had
     * been sent, so the whole file is owed. §15.5.17 reserves 416 for a spec that is
     * well-formed and simply falls outside the file.
     *
     * Collapsing them answered 416 to a client whose header was only malformed, and — for
     * `bytes=abc`, parsed as `0-` — served a 206 covering the entire representation.
     *
     * @param string $range A Range header no parser should accept.
     */
    #[DataProvider('malformedRanges')]
    public function test_a_malformed_range_is_ignored_and_the_whole_file_is_sent(string $range): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path), new StubRequest('GET', ['Range' => $range]));

        self::assertSame(HttpCode::OK->value, $res->statusCode, 'a malformed Range is not a refusal');
        self::assertArrayNotHasKey('Content-Range', $res->headers);
        self::assertSame((string) self::SIZE, $res->headers['Content-Length']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedRanges(): iterable
    {
        yield 'end before start' => ['bytes=5-2'];
        yield 'empty range-set'  => ['bytes='];
        yield 'a bare dash'      => ['bytes=-'];
        yield 'not a number'     => ['bytes=abc'];
        yield 'trailing junk'    => ['bytes=0-99x'];
        yield 'negative start'   => ['bytes=-5-9'];
    }

    /**
     * @param string $range A Range that parses but cannot be met.
     */
    #[DataProvider('unsatisfiableRanges')]
    public function test_a_well_formed_range_outside_the_file_is_refused(string $range): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path), new StubRequest('GET', ['Range' => $range]));

        self::assertSame(HttpCode::REQUESTED_RANGE_NOT_SATISFIABLE->value, $res->statusCode);
        self::assertSame('bytes */' . self::SIZE, $res->headers['Content-Range']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsatisfiableRanges(): iterable
    {
        yield 'starts past the end' => ['bytes=99999-'];
        yield 'a suffix of nothing' => ['bytes=-0'];
    }

    /** An unknown unit is not ours to interpret, so the Range is ignored. */
    public function test_an_unknown_range_unit_is_ignored(): void
    {
        $res = $this->send(ResponseStreamFile::open($this->path), new StubRequest('GET', ['Range' => 'items=0-99']));

        self::assertSame(HttpCode::OK->value, $res->statusCode);
        self::assertArrayNotHasKey('Content-Range', $res->headers);
    }

    // ── Resource headers survive 304 and 416 ──────────────────────────────────

    /**
     * A 304 that omits `Cache-Control` leaves the client on the one it stored: RFC 9111
     * §4.3.4 has a cache update its copy from the fields the 304 carries, and an absent
     * field simply keeps its old value.
     *
     * So a policy changed from `public` to `no-store` would never reach anyone who
     * already held a copy, and a link whose `max-age` counts down from its remaining
     * lifetime would freeze at the first value the client ever saw — outliving the link.
     */
    public function test_cache_policy_reaches_a_revalidating_client(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->maxAge(600),
            new StubRequest('GET', ['If-None-Match' => $this->etag]),
        );

        self::assertSame(HttpCode::NOT_MODIFIED->value, $res->statusCode);
        self::assertStringContainsString('max-age=600', $res->headers['Cache-Control']);
    }

    public function test_the_callers_own_headers_reach_a_revalidating_client(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->header('X-Bucket-Policy', 'private'),
            new StubRequest('GET', ['If-None-Match' => $this->etag]),
        );

        self::assertSame('private', $res->headers['X-Bucket-Policy']);
    }

    /** The caller asked for the cookie; revalidation is no reason to eat it. */
    public function test_a_cookie_survives_a_304(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->cookie(SetCookie::make('seen', '1')),
            new StubRequest('GET', ['If-None-Match' => $this->etag]),
        );

        self::assertCount(1, $res->cookies);
    }

    public function test_resource_headers_reach_a_refused_range_too(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->maxAge(600)->cookie(SetCookie::make('seen', '1')),
            new StubRequest('GET', ['Range' => 'bytes=999999-']),
        );

        self::assertSame(HttpCode::REQUESTED_RANGE_NOT_SATISFIABLE->value, $res->statusCode);
        self::assertStringContainsString('max-age=600', $res->headers['Cache-Control']);
        self::assertCount(1, $res->cookies);
    }

    /**
     * Swoole fills an unset `Content-Type` with `text/html`, and RFC 9111 §4.3.4 has a
     * cache replace its stored fields with the ones a 304 carries — so a client holding
     * `image/png` would come back from revalidation holding `text/html`. Stating the true
     * type is the lesser deviation from RFC 9110 §15.4.5, which would rather no
     * representation metadata travelled on a 304 at all.
     */
    public function test_the_true_content_type_travels_on_a_304(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->contentType('image/png'),
            new StubRequest('GET', ['If-None-Match' => $this->etag]),
        );

        self::assertSame(HttpCode::NOT_MODIFIED->value, $res->statusCode);
        self::assertSame('image/png', $res->headers['Content-Type']);
    }

    public function test_the_true_content_type_travels_on_a_416(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->contentType('image/png'),
            new StubRequest('GET', ['Range' => 'bytes=999999-']),
        );

        self::assertSame(HttpCode::REQUESTED_RANGE_NOT_SATISFIABLE->value, $res->statusCode);
        self::assertSame('image/png', $res->headers['Content-Type']);
    }

    /** Everything else about a bodiless response stays absent. */
    public function test_representation_headers_stay_off_a_304(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path),
            new StubRequest('GET', ['If-None-Match' => $this->etag]),
        );

        foreach (['Content-Disposition', 'Content-Length', 'Content-Encoding'] as $header) {
            self::assertArrayNotHasKey($header, $res->headers);
        }
    }

    /** ->header() still reads as an override of what the class would otherwise set. */
    public function test_a_caller_header_still_overrides_a_content_header(): void
    {
        $res = $this->send(
            ResponseStreamFile::open($this->path)->header('Content-Type', 'application/x-custom'),
            new StubRequest(),
        );

        self::assertSame('application/x-custom', $res->headers['Content-Type']);
    }

    // ── The file can vanish between building and sending ──────────────────────

    /**
     * `open()` proves the file exists when the response is built; nothing keeps it there
     * until it is written, and a blob cleaner runs on its own schedule. Swallowing the
     * failed stat sent 200 with `Content-Length: 0`, an `ETag` of `"0-0"` and — with
     * `maxAge()` set — a `Cache-Control` telling the client to remember that emptiness
     * for a path that still exists.
     */
    public function test_a_file_that_disappeared_is_not_served_as_empty(): void
    {
        $response = ResponseStreamFile::open($this->path)->maxAge(3600);
        unlink($this->path);

        try {
            $res = $this->send($response, new StubRequest());
            self::fail('an empty 200 should never leave here');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('disappeared', $e->getMessage());
            self::assertStringContainsString($this->path, $e->getMessage(), 'name the path');
        } finally {
            file_put_contents($this->path, str_repeat('A', self::SIZE)); // tearDown unlinks it
        }
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