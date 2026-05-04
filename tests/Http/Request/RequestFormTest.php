<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\K2\Http\Request\Validation\Min;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture DTOs ──────────────────────────────────────────────────────────────

class Form_ProductDto
{
    public function __construct(
        public readonly string $name,
        public readonly int    $price,
        public readonly ?string $description = null,
    ) {}
}

class Form_ValidatedDto
{
    public function __construct(
        #[Required] #[NotBlank]
        public readonly string $username,
        #[Min(8)]
        public readonly int    $passwordLength,
    ) {}
}

class Form_AddressDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $city,
        public readonly string $zip,
    ) {}
}

class Form_NestedPersonDto
{
    public function __construct(
        #[NotBlank]
        public readonly string       $name,
        public readonly Form_AddressDto $address,
    ) {}
}

class Form_VariadicItemDto
{
    public function __construct(
        public readonly string $name,
        #[Positive]
        public readonly int    $qty,
    ) {}
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestFormFixture
{
    public function asArray(#[RequestForm] array $body): void {}
    public function asStdClass(#[RequestForm] \stdClass $body): void {}
    public function asDto(#[RequestForm] Form_ProductDto $body): void {}
    public function asValidated(#[Valid] #[RequestForm] Form_ValidatedDto $body): void {}
    public function asNestedPerson(#[Valid] #[RequestForm] Form_NestedPersonDto $body): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestFormTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function makeRequest(array $formData, array $queryParams = []): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getParsedBody')->willReturn($formData);
        $req->method('getQueryParams')->willReturn($queryParams);
        $req->method('getRawBody')->willReturn('');
        return $req;
    }

    private function resolve(string $method, array $formData): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(RequestFormFixture::class, $method),
            $this->makeRequest($formData),
            $this->response,
            [],
        );
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function test_array_returns_form_data(): void
    {
        [$body] = $this->resolve('asArray', ['name' => 'Widget', 'price' => '99']);
        $this->assertSame('Widget', $body['name']);
        $this->assertSame('99', $body['price']);
    }

    public function test_array_empty_form(): void
    {
        [$body] = $this->resolve('asArray', []);
        $this->assertSame([], $body);
    }

    // ── stdClass ──────────────────────────────────────────────────────────────

    public function test_stdclass_cast(): void
    {
        [$body] = $this->resolve('asStdClass', ['key' => 'value', 'num' => '5']);
        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame('value', $body->key);
        $this->assertSame('5', $body->num);
    }

    // ── DTO hydration ─────────────────────────────────────────────────────────

    public function test_dto_hydrated(): void
    {
        [$dto] = $this->resolve('asDto', ['name' => 'Laptop', 'price' => '1500']);
        $this->assertInstanceOf(Form_ProductDto::class, $dto);
        $this->assertSame('Laptop', $dto->name);
        $this->assertSame(1500, $dto->price);
        $this->assertNull($dto->description);
    }

    public function test_dto_optional_field_present(): void
    {
        [$dto] = $this->resolve('asDto', ['name' => 'Laptop', 'price' => '1500', 'description' => 'Fast laptop']);
        $this->assertSame('Fast laptop', $dto->description);
    }

    public function test_dto_missing_required_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('asDto', ['name' => 'Laptop']);
    }

    public function test_form_body_merged_with_query_params(): void
    {
        // parsedBody fields take precedence over query params (+ operator, left side wins)
        $req = $this->createStub(HttpResponse::class);
        $request = $this->createStub(HttpRequest::class);
        $request->method('getParsedBody')->willReturn(['name' => 'FormName', 'price' => '50']);
        $request->method('getQueryParams')->willReturn(['name' => 'QueryName', 'extra' => 'x']);
        $request->method('getRawBody')->willReturn('');

        [$dto] = ParameterResolver::resolve(
            new ReflectionMethod(RequestFormFixture::class, 'asArray'),
            $request,
            $this->response,
            [],
        );

        $this->assertSame('FormName', $dto['name']);
        $this->assertSame('x', $dto['extra']);
    }

    public function test_dto_multiple_missing_fields_all_reported(): void
    {
        try {
            $this->resolve('asDto', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('price', $errors);
        }
    }

    // ── nested DTO hydration from form ────────────────────────────────────────

    public function test_nested_dto_hydrated_from_form(): void
    {
        // PHP form encoding supports nested arrays: address[city]=..., address[zip]=...
        $request = $this->makeRequest([
            'name'    => 'Alice',
            'address' => ['city' => 'Berlin', 'zip' => '10115'],
        ]);
        [$dto] = ParameterResolver::resolve(
            new ReflectionMethod(RequestFormFixture::class, 'asNestedPerson'),
            $request,
            $this->response,
            [],
        );
        $this->assertInstanceOf(Form_NestedPersonDto::class, $dto);
        $this->assertSame('Alice', $dto->name);
        $this->assertInstanceOf(Form_AddressDto::class, $dto->address);
        $this->assertSame('Berlin', $dto->address->city);
    }

    public function test_nested_dto_missing_inner_field_dot_path(): void
    {
        $request = $this->makeRequest([
            'name'    => 'Alice',
            'address' => ['zip' => '10115'],
        ]);
        try {
            ParameterResolver::resolve(
                new ReflectionMethod(RequestFormFixture::class, 'asNestedPerson'),
                $request,
                $this->response,
                [],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address.city', $e->getErrors());
        }
    }

    public function test_nested_valid_outer_and_inner_errors_both_reported(): void
    {
        $request = $this->makeRequest([
            'name'    => '',
            'address' => ['city' => '', 'zip' => '10115'],
        ]);
        try {
            ParameterResolver::resolve(
                new ReflectionMethod(RequestFormFixture::class, 'asNestedPerson'),
                $request,
                $this->response,
                [],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('address.city', $errors);
        }
    }

    // ── #[Valid] integration ──────────────────────────────────────────────────

    public function test_valid_passes(): void
    {
        [$dto] = $this->resolve('asValidated', ['username' => 'alice', 'passwordLength' => '12']);
        $this->assertSame('alice', $dto->username);
        $this->assertSame(12, $dto->passwordLength);
    }

    public function test_valid_blank_username_fails(): void
    {
        try {
            $this->resolve('asValidated', ['username' => '   ', 'passwordLength' => '12']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('username', $e->getErrors());
        }
    }

    public function test_valid_short_password_fails(): void
    {
        try {
            $this->resolve('asValidated', ['username' => 'alice', 'passwordLength' => '4']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('passwordLength', $e->getErrors());
        }
    }
}
