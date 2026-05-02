<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestQuery;
use Flytachi\Winter\K2\Http\Request\RequestException;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture DTOs ──────────────────────────────────────────────────────────────

enum Query_Status: string
{
    case ACTIVE   = 'active';
    case INACTIVE = 'inactive';
}

class Query_FilterDto
{
    public function __construct(
        public readonly int     $page   = 1,
        public readonly int     $limit  = 20,
        public readonly ?string $search = null,
    ) {}
}

class Query_TypedDto
{
    public function __construct(
        public readonly int              $page,
        public readonly float            $minRating,
        public readonly bool             $active,
        public readonly ?string          $q      = null,
        public readonly ?Query_Status    $status = null,
        public readonly ?\DateTimeImmutable $from = null,
    ) {}
}

class Query_RequiredDto
{
    public function __construct(
        public readonly string $name,
        public readonly int    $age,
    ) {}
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestQueryFixture
{
    public function asArray(#[RequestQuery] array $params): void {}
    public function asStdClass(#[RequestQuery] \stdClass $params): void {}
    public function asFilter(#[RequestQuery] Query_FilterDto $filter): void {}
    public function asTyped(#[RequestQuery] Query_TypedDto $dto): void {}
    public function asRequired(#[RequestQuery] Query_RequiredDto $dto): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestQueryTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function makeRequest(array $queryParams): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getQueryParams')->willReturn($queryParams);
        return $req;
    }

    private function resolve(string $method, array $queryParams): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(RequestQueryFixture::class, $method),
            $this->makeRequest($queryParams),
            $this->response,
            [],
        );
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function test_array_returns_raw_params(): void
    {
        [$params] = $this->resolve('asArray', ['page' => '2', 'sort' => 'name']);
        $this->assertSame(['page' => '2', 'sort' => 'name'], $params);
    }

    public function test_array_empty_query_returns_empty_array(): void
    {
        [$params] = $this->resolve('asArray', []);
        $this->assertSame([], $params);
    }

    // ── stdClass ──────────────────────────────────────────────────────────────

    public function test_stdclass_cast_from_query(): void
    {
        [$obj] = $this->resolve('asStdClass', ['page' => '2', 'q' => 'hello']);
        $this->assertInstanceOf(\stdClass::class, $obj);
        $this->assertSame('2', $obj->page);
        $this->assertSame('hello', $obj->q);
    }

    public function test_stdclass_empty_query_returns_empty_object(): void
    {
        [$obj] = $this->resolve('asStdClass', []);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    // ── DTO with defaults — always optional ───────────────────────────────────

    public function test_dto_empty_query_uses_all_defaults(): void
    {
        [$dto] = $this->resolve('asFilter', []);
        $this->assertInstanceOf(Query_FilterDto::class, $dto);
        $this->assertSame(1, $dto->page);
        $this->assertSame(20, $dto->limit);
        $this->assertNull($dto->search);
    }

    public function test_dto_all_params_provided(): void
    {
        [$dto] = $this->resolve('asFilter', ['page' => '3', 'limit' => '50', 'search' => 'hello']);
        $this->assertSame(3, $dto->page);
        $this->assertSame(50, $dto->limit);
        $this->assertSame('hello', $dto->search);
    }

    public function test_dto_partial_params_rest_use_defaults(): void
    {
        [$dto] = $this->resolve('asFilter', ['page' => '2']);
        $this->assertSame(2, $dto->page);
        $this->assertSame(20, $dto->limit);
        $this->assertNull($dto->search);
    }

    public function test_dto_nullable_field_absent_is_null(): void
    {
        [$dto] = $this->resolve('asFilter', ['page' => '1', 'limit' => '10']);
        $this->assertNull($dto->search);
    }

    // ── Type casting ──────────────────────────────────────────────────────────

    public function test_int_cast_from_string(): void
    {
        [$dto] = $this->resolve('asFilter', ['page' => '5', 'limit' => '100']);
        $this->assertSame(5, $dto->page);
        $this->assertSame(100, $dto->limit);
    }

    public function test_float_cast(): void
    {
        [$dto] = $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '4.5', 'active' => 'true',
        ]);
        $this->assertSame(4.5, $dto->minRating);
    }

    public function test_bool_true_cast(): void
    {
        [$dto] = $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '0', 'active' => 'true',
        ]);
        $this->assertTrue($dto->active);
    }

    public function test_bool_false_cast(): void
    {
        [$dto] = $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '0', 'active' => 'false',
        ]);
        $this->assertFalse($dto->active);
    }

    public function test_nullable_string_absent_is_null(): void
    {
        [$dto] = $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '0', 'active' => '1',
        ]);
        $this->assertNull($dto->q);
    }

    public function test_enum_cast_from_string(): void
    {
        [$dto] = $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '0', 'active' => '1', 'status' => 'active',
        ]);
        $this->assertSame(Query_Status::ACTIVE, $dto->status);
    }

    public function test_enum_invalid_value_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '0', 'active' => '1', 'status' => 'unknown',
        ]);
    }

    public function test_datetime_cast(): void
    {
        [$dto] = $this->resolve('asTyped', [
            'page' => '1', 'minRating' => '0', 'active' => '1', 'from' => '2024-06-01',
        ]);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->from);
        $this->assertSame('2024-06-01', $dto->from->format('Y-m-d'));
    }

    // ── Required fields missing ───────────────────────────────────────────────

    public function test_missing_required_field_throws_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('asRequired', []);
    }

    public function test_missing_one_of_required_fields_error_shows_field(): void
    {
        try {
            $this->resolve('asRequired', ['name' => 'Alice']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('age', $e->getErrors());
        }
    }

    public function test_all_required_fields_provided_passes(): void
    {
        [$dto] = $this->resolve('asRequired', ['name' => 'Alice', 'age' => '30']);
        $this->assertSame('Alice', $dto->name);
        $this->assertSame(30, $dto->age);
    }

    // ── LogicException for unsupported type ───────────────────────────────────

    public function test_unsupported_scalar_type_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[RequestQuery]');

        $fixture = new class {
            public function action(#[RequestQuery] string $q): void {}
        };

        ParameterResolver::resolve(
            new ReflectionMethod($fixture::class, 'action'),
            $this->makeRequest([]),
            $this->response,
            [],
        );
    }
}
