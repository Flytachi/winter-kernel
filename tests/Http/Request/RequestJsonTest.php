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
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
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

class Json_VariadicItemDto
{
    public function __construct(
        public readonly string $name,
        #[Positive]
        public readonly int    $qty,
    ) {}
}

class Json_ConstrainedAddressDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $city,
        public readonly string $zip,
    ) {}
}

class Json_ConstrainedPersonDto
{
    public function __construct(
        #[NotBlank]
        public readonly string                  $name,
        public readonly Json_ConstrainedAddressDto $address,
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
    public function asMixed(#[RequestJson, Valid] Json_MixedErrorDto $body): void {}
    public function asVariadic(#[RequestJson] Json_VariadicItemDto ...$items): void {}
    public function asVariadicValid(#[RequestJson, Valid] Json_VariadicItemDto ...$items): void {}
    public function asConstrainedPerson(#[RequestJson, Valid] Json_ConstrainedPersonDto $body): void {}
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

    // ── variadic ──────────────────────────────────────────────────────────────

    public function test_variadic_empty_array_returns_no_items(): void
    {
        $result = $this->resolve('asVariadic', '[]');
        $this->assertSame([], $result);
    }

    public function test_variadic_single_item_hydrated(): void
    {
        $result = $this->resolve('asVariadic', '[{"name":"Widget","qty":3}]');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Json_VariadicItemDto::class, $result[0]);
        $this->assertSame('Widget', $result[0]->name);
        $this->assertSame(3, $result[0]->qty);
    }

    public function test_variadic_multiple_items_hydrated(): void
    {
        $result = $this->resolve('asVariadic', '[{"name":"A","qty":1},{"name":"B","qty":2}]');
        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]->name);
        $this->assertSame('B', $result[1]->name);
    }

    public function test_variadic_non_array_json_throws(): void
    {
        $this->expectException(\Flytachi\Winter\K2\Http\Request\RequestException::class);
        $this->resolve('asVariadic', '{"name":"Widget","qty":1}');
    }

    public function test_variadic_structural_error_uses_index_key(): void
    {
        try {
            $this->resolve('asVariadic', '[{"name":"A","qty":1},{"qty":2}]');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('[1].name', $e->getErrors());
        }
    }

    public function test_variadic_errors_collected_from_multiple_elements(): void
    {
        try {
            $this->resolve('asVariadic', '[{"qty":1},{"name":"B"}]');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('[0].name', $errors);
            $this->assertArrayHasKey('[1].qty', $errors);
        }
    }

    public function test_variadic_valid_constraint_violation_uses_index_key(): void
    {
        try {
            $this->resolve('asVariadicValid', '[{"name":"Good","qty":1},{"name":"Bad","qty":-5}]');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('[1].qty', $e->getErrors());
        }
    }

    public function test_variadic_valid_passes_when_all_valid(): void
    {
        $result = $this->resolve('asVariadicValid', '[{"name":"A","qty":1},{"name":"B","qty":2}]');
        $this->assertCount(2, $result);
    }

    // ── nested DTO + #[Valid] constraint cascade ───────────────────────────────

    public function test_nested_valid_constraint_on_outer_field(): void
    {
        $json = '{"name":"","address":{"city":"Berlin","zip":"10115"}}';
        try {
            $this->resolve('asConstrainedPerson', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->getErrors());
        }
    }

    public function test_nested_valid_constraint_on_inner_field(): void
    {
        $json = '{"name":"Alice","address":{"city":"","zip":"10115"}}';
        try {
            $this->resolve('asConstrainedPerson', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address.city', $e->getErrors());
        }
    }

    public function test_nested_valid_outer_and_inner_errors_both_reported(): void
    {
        $json = '{"name":"","address":{"city":"","zip":"10115"}}';
        try {
            $this->resolve('asConstrainedPerson', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('address.city', $errors);
        }
    }

    public function test_nested_valid_passes_when_all_valid(): void
    {
        $json = '{"name":"Alice","address":{"city":"Berlin","zip":"10115"}}';
        [$dto] = $this->resolve('asConstrainedPerson', $json);
        $this->assertSame('Alice', $dto->name);
        $this->assertSame('Berlin', $dto->address->city);
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
