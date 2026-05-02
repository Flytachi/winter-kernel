<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Validation\In;
use Flytachi\Winter\K2\Http\Request\Validation\Min;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture DTOs ──────────────────────────────────────────────────────────────

class Json_FilterDto
{
    public function __construct(
        public readonly int    $minPrice,
        public readonly int    $maxPrice,
        public readonly ?string $category = null,
    ) {}
}

class Json_OrderDto
{
    public function __construct(
        public readonly string       $title,
        public readonly Json_FilterDto $filter,
    ) {}
}

class Json_ValidatedDto
{
    public function __construct(
        #[Required] #[NotBlank]
        public readonly string $name,
        #[Min(0)]
        public readonly int    $qty,
    ) {}
}

class Json_MixedErrorDto
{
    public function __construct(
        public readonly int    $code,
        #[In(['asc', 'desc'])]
        public readonly string $order,
    ) {}
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestJsonFixture
{
    public function asArray(#[RequestJson] array $body): void {}
    public function asStdClass(#[RequestJson] \stdClass $body): void {}
    public function asDto(#[RequestJson] Json_FilterDto $body): void {}
    public function asNested(#[RequestJson] Json_OrderDto $body): void {}
    public function asValidated(#[Valid] #[RequestJson] Json_ValidatedDto $body): void {}
    public function asMixed(#[RequestJson] Json_MixedErrorDto $body): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestJsonTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function makeRequest(string $rawBody): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getRawBody')->willReturn($rawBody);
        $req->method('getQueryParams')->willReturn([]);
        return $req;
    }

    private function resolve(string $method, string $rawBody): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(RequestJsonFixture::class, $method),
            $this->makeRequest($rawBody),
            $this->response,
            [],
        );
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function test_array_parsed(): void
    {
        [$body] = $this->resolve('asArray', '{"a":1,"b":2}');
        $this->assertSame(['a' => 1, 'b' => 2], $body);
    }

    public function test_array_invalid_json_returns_empty(): void
    {
        [$body] = $this->resolve('asArray', 'not-json');
        $this->assertSame([], $body);
    }

    public function test_array_nested_parsed(): void
    {
        [$body] = $this->resolve('asArray', '{"list":[1,2,3]}');
        $this->assertSame([1, 2, 3], $body['list']);
    }

    // ── stdClass ──────────────────────────────────────────────────────────────

    public function test_stdclass_parsed(): void
    {
        [$body] = $this->resolve('asStdClass', '{"name":"Alice","age":30}');
        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame('Alice', $body->name);
        $this->assertSame(30, $body->age);
    }

    public function test_stdclass_empty_body_returns_empty_object(): void
    {
        [$body] = $this->resolve('asStdClass', '');
        $this->assertInstanceOf(\stdClass::class, $body);
    }

    // ── DTO hydration ─────────────────────────────────────────────────────────

    public function test_dto_hydrated(): void
    {
        [$dto] = $this->resolve('asDto', '{"minPrice":10,"maxPrice":500}');
        $this->assertInstanceOf(Json_FilterDto::class, $dto);
        $this->assertSame(10, $dto->minPrice);
        $this->assertSame(500, $dto->maxPrice);
        $this->assertNull($dto->category);
    }

    public function test_dto_optional_field_present(): void
    {
        [$dto] = $this->resolve('asDto', '{"minPrice":0,"maxPrice":100,"category":"electronics"}');
        $this->assertSame('electronics', $dto->category);
    }

    public function test_dto_missing_required_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('asDto', '{"minPrice":10}');
    }

    // ── nested DTO ────────────────────────────────────────────────────────────

    public function test_nested_dto_hydrated(): void
    {
        $json = '{"title":"Search","filter":{"minPrice":5,"maxPrice":200}}';
        [$dto] = $this->resolve('asNested', $json);
        $this->assertInstanceOf(Json_OrderDto::class, $dto);
        $this->assertSame('Search', $dto->title);
        $this->assertInstanceOf(Json_FilterDto::class, $dto->filter);
        $this->assertSame(5, $dto->filter->minPrice);
    }

    public function test_nested_dto_missing_field_dot_path(): void
    {
        $json = '{"title":"Search","filter":{"minPrice":5}}';
        try {
            $this->resolve('asNested', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filter.maxPrice', $e->getErrors());
        }
    }

    public function test_nested_dto_wrong_type_error(): void
    {
        $json = '{"title":"Search","filter":"not-object"}';
        try {
            $this->resolve('asNested', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filter', $e->getErrors());
            $this->assertStringContainsString('must be an object', $e->getErrors()['filter'][0]);
        }
    }

    // ── #[Valid] integration ──────────────────────────────────────────────────

    public function test_valid_passes(): void
    {
        [$dto] = $this->resolve('asValidated', '{"name":"Widget","qty":10}');
        $this->assertSame('Widget', $dto->name);
    }

    public function test_valid_blank_name_fails(): void
    {
        try {
            $this->resolve('asValidated', '{"name":"  ","qty":5}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->getErrors());
        }
    }

    public function test_valid_negative_qty_fails(): void
    {
        try {
            $this->resolve('asValidated', '{"name":"Item","qty":-1}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty', $e->getErrors());
        }
    }

    // ── hydration error + constraint error reported together ──────────────────

    public function test_hydration_and_constraint_errors_both_reported(): void
    {
        // code: missing (hydration error) + order: bad value (constraint error)
        // previously only the hydration error was returned; constraint was swallowed
        try {
            $this->resolve('asMixed', '{"order":"bad"}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('code', $errors,  'hydration error must be present');
            $this->assertArrayHasKey('order', $errors, 'constraint error must be present');
        }
    }
}
