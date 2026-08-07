<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\ParameterResolver;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestFile;
use Flytachi\Winter\Kernel\Http\Request\RequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestFileFixture
{
    public function single(#[RequestFile('avatar')] array $file): void {}
    public function singleString(#[RequestFile('avatar')] string $bytes): void {}
    public function singleNullable(#[RequestFile('avatar')] ?array $file): void {}
    public function singleDefault(#[RequestFile('avatar')] ?array $file = null): void {}
    public function multiple(#[RequestFile('images', multiple: true)] array $images): void {}
    public function allFiles(#[RequestFile] array $files): void {}
    public function withMaxSize(#[RequestFile('avatar', maxSize: '500KB')] array $file): void {}
    public function withAcceptMime(#[RequestFile('avatar', accept: ['image/jpeg'])] array $file): void {}
    public function withAcceptWildcard(#[RequestFile('avatar', accept: ['image/*'])] array $file): void {}
    public function withAcceptExt(#[RequestFile('avatar', accept: ['.jpg'])] array $file): void {}
    public function multipleWithConstraints(#[RequestFile('images', multiple: true, maxSize: '1MB', accept: ['image/*'])] array $images): void {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

class RequestFileTest extends TestCase
{
    private HttpRequest  $request;
    private HttpResponse $response;
    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->request  = $this->createStub(HttpRequest::class);
        $this->response = $this->createStub(HttpResponse::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function resolve(string $method, array $uploadedFiles): array
    {
        $this->request->method('getUploadedFiles')->willReturn($uploadedFiles);
        return ParameterResolver::resolve(
            new ReflectionMethod(RequestFileFixture::class, $method),
            $this->request,
            $this->response,
            [],
        );
    }

    private function makeFile(string $content = 'test content', string $name = 'file.txt'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'winter_rf_');
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return ['name' => $name, 'type' => 'text/plain', 'tmp_name' => $path, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK];
    }

    private function makeJpeg(string $name = 'photo.jpg'): array
    {
        // JPEG magic bytes FF D8 FF E0
        return $this->makeFile("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 20), $name);
    }

    private function makePng(string $name = 'image.png'): array
    {
        // minimal valid 1x1 transparent PNG
        $content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVQI12NgAAIABQAABjE+ibYAAAAASUVORK5CYII=');
        return $this->makeFile($content, $name);
    }

    // ── Single file ───────────────────────────────────────────────────────────

    public function test_single_returns_file_array(): void
    {
        $file = $this->makeFile();
        [$result] = $this->resolve('single', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_single_string_returns_contents(): void
    {
        $file = $this->makeFile('hello content');
        [$result] = $this->resolve('singleString', ['avatar' => $file]);
        $this->assertSame('hello content', $result);
    }

    public function test_single_missing_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Uploaded file 'avatar' is missing");
        $this->resolve('single', []);
    }

    public function test_single_nullable_absent_returns_null(): void
    {
        [$result] = $this->resolve('singleNullable', []);
        $this->assertNull($result);
    }

    public function test_single_default_absent_returns_default(): void
    {
        [$result] = $this->resolve('singleDefault', []);
        $this->assertNull($result);
    }

    public function test_single_takes_first_when_multiple_uploaded(): void
    {
        $first  = $this->makeFile('first');
        $second = $this->makeFile('second');
        [$result] = $this->resolve('single', ['avatar' => [$first, $second]]);
        $this->assertSame($first, $result);
    }

    // ── Multiple files ────────────────────────────────────────────────────────

    public function test_multiple_returns_list(): void
    {
        $a = $this->makeFile('a');
        $b = $this->makeFile('b');
        [$result] = $this->resolve('multiple', ['images' => [$a, $b]]);
        $this->assertCount(2, $result);
        $this->assertSame($a, $result[0]);
        $this->assertSame($b, $result[1]);
    }

    public function test_multiple_normalizes_single_to_list(): void
    {
        $file = $this->makeFile();
        [$result] = $this->resolve('multiple', ['images' => $file]);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame($file, $result[0]);
    }

    // ── All files (no name) ───────────────────────────────────────────────────

    public function test_all_files_returns_full_map(): void
    {
        $avatar   = $this->makeFile();
        $document = $this->makeFile();
        [$result] = $this->resolve('allFiles', ['avatar' => $avatar, 'document' => $document]);
        $this->assertArrayHasKey('avatar', $result);
        $this->assertArrayHasKey('document', $result);
    }

    // ── Transfer error ────────────────────────────────────────────────────────

    public function test_transfer_error_throws(): void
    {
        $file = $this->makeFile();
        $file['error'] = UPLOAD_ERR_INI_SIZE;
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('transfer error (code ' . UPLOAD_ERR_INI_SIZE . ')');
        $this->resolve('single', ['avatar' => $file]);
    }

    // ── maxSize ───────────────────────────────────────────────────────────────

    public function test_maxsize_within_limit_passes(): void
    {
        $file = $this->makeFile();
        $file['size'] = 512_000; // exactly 500KB
        [$result] = $this->resolve('withMaxSize', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_maxsize_at_boundary_passes(): void
    {
        $file = $this->makeFile();
        $file['size'] = 500 * 1_024; // exactly 500KB — equal is allowed
        [$result] = $this->resolve('withMaxSize', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_maxsize_over_limit_throws(): void
    {
        $file = $this->makeFile();
        $file['size'] = 500 * 1_024 + 1; // 1 byte over
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('exceeds maximum size of 500KB');
        $this->resolve('withMaxSize', ['avatar' => $file]);
    }

    // ── accept: exact MIME ────────────────────────────────────────────────────

    public function test_accept_exact_mime_passes(): void
    {
        $file = $this->makeJpeg();
        [$result] = $this->resolve('withAcceptMime', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_accept_exact_mime_rejected(): void
    {
        $file = $this->makePng(); // PNG, not JPEG
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('is not allowed');
        $this->resolve('withAcceptMime', ['avatar' => $file]);
    }

    // ── accept: wildcard ──────────────────────────────────────────────────────

    public function test_accept_wildcard_matches_jpeg(): void
    {
        $file = $this->makeJpeg();
        [$result] = $this->resolve('withAcceptWildcard', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_accept_wildcard_matches_png(): void
    {
        $file = $this->makePng();
        [$result] = $this->resolve('withAcceptWildcard', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_accept_wildcard_rejects_non_image(): void
    {
        $file = $this->makeFile('plain text content', 'doc.txt'); // text/plain
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('is not allowed');
        $this->resolve('withAcceptWildcard', ['avatar' => $file]);
    }

    // ── accept: extension ─────────────────────────────────────────────────────

    public function test_accept_extension_passes(): void
    {
        $file = $this->makeJpeg('photo.jpg');
        [$result] = $this->resolve('withAcceptExt', ['avatar' => $file]);
        $this->assertSame($file, $result);
    }

    public function test_accept_extension_rejects_wrong_ext(): void
    {
        $file = $this->makeJpeg('photo.png'); // wrong extension
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('is not allowed');
        $this->resolve('withAcceptExt', ['avatar' => $file]);
    }

    // ── multiple with constraints ─────────────────────────────────────────────

    public function test_multiple_with_constraints_validates_each(): void
    {
        $a = $this->makeJpeg('a.jpg');
        $b = $this->makePng('b.png');
        [$result] = $this->resolve('multipleWithConstraints', ['images' => [$a, $b]]);
        $this->assertCount(2, $result);
    }

    public function test_multiple_with_constraints_throws_on_any_violation(): void
    {
        $good = $this->makeJpeg();
        $bad  = $this->makeFile('text content', 'doc.txt');
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('is not allowed');
        $this->resolve('multipleWithConstraints', ['images' => [$good, $bad]]);
    }

    public function test_multiple_maxsize_throws_on_any_violation(): void
    {
        $a = $this->makeJpeg();
        $b = $this->makeJpeg();
        $b['size'] = 2 * 1_048_576; // 2MB > 1MB limit
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('exceeds maximum size of 1MB');
        $this->resolve('multipleWithConstraints', ['images' => [$a, $b]]);
    }
}
