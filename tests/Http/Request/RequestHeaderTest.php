<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestHeader;
use Flytachi\Winter\K2\Http\Request\RequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture enums ─────────────────────────────────────────────────────────────

enum HeaderCode: int
{
    case OK   = 1;
    case FAIL = 0;
}

enum HeaderStatus: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestHeaderFixture
{
    // auto name — snake_case style
    public function snakeStyle(#[RequestHeader] string $x_requested_with): void {}

    // auto name — camelCase style
    public function camelStyle(#[RequestHeader] string $xRequestedWith): void {}

    // single-word — no conversion
    public function singleWord(#[RequestHeader] string $authorization): void {}

    // explicit name — no auto-conversion
    public function explicitName(#[RequestHeader('X-Trace-Id')] string $traceId): void {}

    // optional
    public function optionalHeader(#[RequestHeader('X-Forwarded-For')] ?string $forwardedFor = null): void {}

    // nullable without default
    public function nullableHeader(#[RequestHeader] ?string $xRequestedWith): void {}

    // typed: int
    public function intHeader(#[RequestHeader('X-Retry-Count')] int $retryCount): void {}

    // typed: bool
    public function boolHeader(#[RequestHeader('X-Debug')] bool $debug): void {}

    // typed: float
    public function floatHeader(#[RequestHeader('X-Rate')] float $rate): void {}

    // typed: string-backed enum
    public function enumStringHeader(#[RequestHeader('X-Status')] HeaderStatus $status): void {}

    // typed: int-backed enum
    public function enumIntHeader(#[RequestHeader('X-Code')] HeaderCode $code): void {}

    // required missing
    public function requiredHeader(#[RequestHeader] string $authorization): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestHeaderTest extends TestCase
{
    private HttpRequest  $request;
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->request  = $this->createStub(HttpRequest::class);
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function resolve(string $method, array $headers): array
    {
        $this->request
            ->method('getHeader')
            ->willReturnCallback(fn(string $name) => $headers[$name] ?? null);

        return ParameterResolver::resolve(
            new ReflectionMethod(RequestHeaderFixture::class, $method),
            $this->request,
            $this->response,
            [],
        );
    }

    // ── Auto name conversion — snake_case ─────────────────────────────────────

    public function test_snake_style_resolves(): void
    {
        [$val] = $this->resolve('snakeStyle', ['x-requested-with' => 'XMLHttpRequest']);
        $this->assertSame('XMLHttpRequest', $val);
    }

    // ── Auto name conversion — camelCase ──────────────────────────────────────

    public function test_camel_style_resolves(): void
    {
        [$val] = $this->resolve('camelStyle', ['x-requested-with' => 'XMLHttpRequest']);
        $this->assertSame('XMLHttpRequest', $val);
    }

    public function test_camel_and_snake_produce_same_header_name(): void
    {
        // both $xRequestedWith and $x_requested_with look up the same key
        [$fromCamel] = $this->resolve('camelStyle', ['x-requested-with' => 'fetch']);
        [$fromSnake] = $this->resolve('snakeStyle', ['x-requested-with' => 'fetch']);
        $this->assertSame($fromCamel, $fromSnake);
    }

    // ── Single-word param ─────────────────────────────────────────────────────

    public function test_single_word_resolves(): void
    {
        [$val] = $this->resolve('singleWord', ['authorization' => 'Bearer token123']);
        $this->assertSame('Bearer token123', $val);
    }

    // ── Explicit name — no conversion ─────────────────────────────────────────

    public function test_explicit_name_resolves(): void
    {
        [$val] = $this->resolve('explicitName', ['X-Trace-Id' => 'abc-123']);
        $this->assertSame('abc-123', $val);
    }

    // ── Optional / nullable ───────────────────────────────────────────────────

    public function test_optional_absent_returns_default(): void
    {
        [$val] = $this->resolve('optionalHeader', []);
        $this->assertNull($val);
    }

    public function test_optional_present_returns_value(): void
    {
        [$val] = $this->resolve('optionalHeader', ['X-Forwarded-For' => '1.2.3.4']);
        $this->assertSame('1.2.3.4', $val);
    }

    public function test_nullable_absent_returns_null(): void
    {
        [$val] = $this->resolve('nullableHeader', []);
        $this->assertNull($val);
    }

    // ── Required missing ─────────────────────────────────────────────────────

    public function test_required_missing_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Required header 'authorization' is missing");
        $this->resolve('requiredHeader', []);
    }

    // ── Typed: int ────────────────────────────────────────────────────────────

    public function test_int_header(): void
    {
        [$val] = $this->resolve('intHeader', ['X-Retry-Count' => '3']);
        $this->assertSame(3, $val);
    }

    public function test_int_header_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be an integer");
        $this->resolve('intHeader', ['X-Retry-Count' => 'abc']);
    }

    // ── Typed: bool ───────────────────────────────────────────────────────────

    public function test_bool_header_true(): void
    {
        [$val] = $this->resolve('boolHeader', ['X-Debug' => 'true']);
        $this->assertTrue($val);
    }

    public function test_bool_header_false(): void
    {
        [$val] = $this->resolve('boolHeader', ['X-Debug' => '0']);
        $this->assertFalse($val);
    }

    public function test_bool_header_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be a boolean");
        $this->resolve('boolHeader', ['X-Debug' => 'maybe']);
    }

    // ── Typed: float ──────────────────────────────────────────────────────────

    public function test_float_header(): void
    {
        [$val] = $this->resolve('floatHeader', ['X-Rate' => '1.5']);
        $this->assertSame(1.5, $val);
    }

    // ── Typed: string-backed enum ─────────────────────────────────────────────

    public function test_enum_string_header(): void
    {
        [$val] = $this->resolve('enumStringHeader', ['X-Status' => 'active']);
        $this->assertSame(HeaderStatus::ACTIVE, $val);
    }

    public function test_enum_string_header_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be one of [active, inactive]");
        $this->resolve('enumStringHeader', ['X-Status' => 'unknown']);
    }

    // ── Typed: int-backed enum ────────────────────────────────────────────────

    public function test_enum_int_header(): void
    {
        [$val] = $this->resolve('enumIntHeader', ['X-Code' => '1']);
        $this->assertSame(HeaderCode::OK, $val);
    }

    public function test_enum_int_header_invalid_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("must be one of [1, 0]");
        $this->resolve('enumIntHeader', ['X-Code' => '99']);
    }
}
