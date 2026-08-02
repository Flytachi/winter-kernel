<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\ParameterResolver;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestBody;
use Flytachi\Winter\Kernel\Http\Request\Validation\Constraint;
use Flytachi\Winter\Kernel\Http\Request\Validation\Min;
use Flytachi\Winter\Kernel\Http\Request\Validation\NotBlank;
use Flytachi\Winter\Kernel\Http\Request\Validation\Positive;
use Flytachi\Winter\Kernel\Http\Request\Validation\Required;
use Flytachi\Winter\Kernel\Http\Request\Validation\Size;
use Flytachi\Winter\Kernel\Http\Request\Validation\Valid;
use Flytachi\Winter\Kernel\Http\Request\Validation\ValidationException;
use Flytachi\Winter\Kernel\Http\Request\RequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture DTOs ──────────────────────────────────────────────────────────────

class Body_AddressDto
{
    public function __construct(
        public readonly string $city,
        public readonly string $zip,
    ) {}
}

class Body_PersonDto
{
    public function __construct(
        public readonly string  $name,
        public readonly int     $age,
        public readonly Body_AddressDto $address,
    ) {}
}

class Body_SimpleDto
{
    public function __construct(
        public readonly string $title,
        public readonly int    $amount,
    ) {}
}

class Body_NullableDto
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $email = null,
    ) {}
}

class Body_ValidatedDto
{
    public function __construct(
        #[Required] #[NotBlank]
        public readonly string $title,
        #[Required] #[Min(1)]
        public readonly int    $amount,
    ) {}
}

class Body_DeepDto
{
    public function __construct(
        public readonly string       $label,
        public readonly Body_PersonDto $person,
    ) {}
}

class Body_VariadicItemDto
{
    public function __construct(
        public readonly string $title,
        #[Positive]
        public readonly int    $amount,
    ) {}
}

class Body_ConstrainedAddressDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $city,
        public readonly string $zip,
    ) {}
}

class Body_ConstrainedPersonDto
{
    public function __construct(
        #[NotBlank]
        public readonly string                     $name,
        public readonly Body_ConstrainedAddressDto $address,
    ) {}
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestBodyFixture
{
    public function rawString(#[RequestBody] string $body): void {}
    public function asArray(#[RequestBody] array $body): void {}
    public function asStdClass(#[RequestBody] \stdClass $body): void {}
    public function asObject(#[RequestBody] object $body): void {}
    public function asDto(#[RequestBody] Body_SimpleDto $body): void {}
    public function asNullable(#[RequestBody] Body_NullableDto $body): void {}
    public function asNested(#[RequestBody] Body_PersonDto $body): void {}
    public function asDeep(#[RequestBody] Body_DeepDto $body): void {}
    public function asValidated(#[Valid] #[RequestBody] Body_ValidatedDto $body): void {}
    public function asXmlArray(#[RequestBody] array $body): void {}
    public function asXmlStdClass(#[RequestBody] \stdClass $body): void {}
    public function asXmlDto(#[RequestBody] Body_SimpleDto $body): void {}
    public function asVariadic(#[RequestBody] Body_VariadicItemDto ...$items): void {}
    public function asVariadicValid(#[Valid] #[RequestBody] Body_VariadicItemDto ...$items): void {}
    public function asConstrainedNested(#[Valid] #[RequestBody] Body_ConstrainedPersonDto $body): void {}

    // ── field mode ──
    public function fieldString(#[RequestBody(field: 'name'), Size(5, 40)] string $name): void {}
    public function fieldInt(#[RequestBody(field: 'age')] int $age): void {}
    public function fieldNested(#[RequestBody(field: 'filter.minPrice')] int $minPrice): void {}
    public function fieldOptional(#[RequestBody(field: 'name')] ?string $name = null): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestBodyTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function makeRequest(string $rawBody, string $contentType = 'application/json'): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getRawBody')->willReturn($rawBody);
        $req->method('getHeader')->willReturn($contentType);
        $req->method('getQueryParams')->willReturn([]);
        return $req;
    }

    private function resolve(string $method, HttpRequest $request): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(RequestBodyFixture::class, $method),
            $request,
            $this->response,
            [],
        );
    }

    // ── string type — raw body ────────────────────────────────────────────────

    public function test_string_returns_raw_body(): void
    {
        [$body] = $this->resolve('rawString', $this->makeRequest('{"key":"val"}'));
        $this->assertSame('{"key":"val"}', $body);
    }

    public function test_string_empty_body(): void
    {
        [$body] = $this->resolve('rawString', $this->makeRequest(''));
        $this->assertSame('', $body);
    }

    // ── array type ────────────────────────────────────────────────────────────

    public function test_array_json_decoded(): void
    {
        [$body] = $this->resolve('asArray', $this->makeRequest('{"a":1,"b":2}'));
        $this->assertSame(['a' => 1, 'b' => 2], $body);
    }

    public function test_array_invalid_json_returns_empty(): void
    {
        [$body] = $this->resolve('asArray', $this->makeRequest('not-json'));
        $this->assertSame([], $body);
    }

    public function test_array_xml_content_type(): void
    {
        $xml = '<root><city>Moscow</city><zip>12345</zip></root>';
        [$body] = $this->resolve('asXmlArray', $this->makeRequest($xml, 'application/xml'));
        $this->assertSame('Moscow', $body['city']);
        $this->assertSame('12345', $body['zip']);
    }

    // ── stdClass / object type ────────────────────────────────────────────────

    public function test_stdclass_json_decoded(): void
    {
        [$body] = $this->resolve('asStdClass', $this->makeRequest('{"name":"Alice"}'));
        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame('Alice', $body->name);
    }

    public function test_object_json_decoded(): void
    {
        [$body] = $this->resolve('asObject', $this->makeRequest('{"x":42}'));
        $this->assertSame(42, $body->x);
    }

    public function test_stdclass_xml_content_type(): void
    {
        $xml = '<root><city>Paris</city></root>';
        [$body] = $this->resolve('asXmlStdClass', $this->makeRequest($xml, 'text/xml'));
        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame('Paris', $body->city);
    }

    // ── plain DTO hydration ───────────────────────────────────────────────────

    public function test_dto_hydrated_from_json(): void
    {
        $json = '{"title":"Order #1","amount":100}';
        [$dto] = $this->resolve('asDto', $this->makeRequest($json));
        $this->assertInstanceOf(Body_SimpleDto::class, $dto);
        $this->assertSame('Order #1', $dto->title);
        $this->assertSame(100, $dto->amount);
    }

    public function test_dto_nullable_field_absent(): void
    {
        $json = '{"name":"Alice"}';
        [$dto] = $this->resolve('asNullable', $this->makeRequest($json));
        $this->assertSame('Alice', $dto->name);
        $this->assertNull($dto->email);
    }

    public function test_dto_nullable_field_present(): void
    {
        $json = '{"name":"Alice","email":"a@example.com"}';
        [$dto] = $this->resolve('asNullable', $this->makeRequest($json));
        $this->assertSame('a@example.com', $dto->email);
    }

    public function test_dto_missing_required_field_throws(): void
    {
        $this->expectException(ValidationException::class);
        $json = '{"title":"Only title"}';
        $this->resolve('asDto', $this->makeRequest($json));
    }

    public function test_dto_xml_hydration(): void
    {
        $xml = '<root><title>Widget</title><amount>5</amount></root>';
        [$dto] = $this->resolve('asXmlDto', $this->makeRequest($xml, 'application/xml'));
        $this->assertInstanceOf(Body_SimpleDto::class, $dto);
        $this->assertSame('Widget', $dto->title);
        $this->assertSame(5, $dto->amount);
    }

    // ── nested DTO hydration ──────────────────────────────────────────────────

    public function test_nested_dto_hydrated(): void
    {
        $json = '{"name":"Bob","age":30,"address":{"city":"Berlin","zip":"10115"}}';
        [$dto] = $this->resolve('asNested', $this->makeRequest($json));
        $this->assertInstanceOf(Body_PersonDto::class, $dto);
        $this->assertSame('Bob', $dto->name);
        $this->assertSame(30, $dto->age);
        $this->assertInstanceOf(Body_AddressDto::class, $dto->address);
        $this->assertSame('Berlin', $dto->address->city);
        $this->assertSame('10115', $dto->address->zip);
    }

    public function test_nested_dto_missing_field_uses_dot_notation(): void
    {
        $json = '{"name":"Bob","age":30,"address":{"city":"Berlin"}}';
        try {
            $this->resolve('asNested', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('address.zip', $errors);
        }
    }

    public function test_deeply_nested_dto_hydrated(): void
    {
        $json = json_encode([
            'label'  => 'Deep',
            'person' => [
                'name'    => 'Carol',
                'age'     => 25,
                'address' => ['city' => 'Tokyo', 'zip' => '100-0001'],
            ],
        ]);
        [$dto] = $this->resolve('asDeep', $this->makeRequest($json));
        $this->assertSame('Deep', $dto->label);
        $this->assertSame('Carol', $dto->person->name);
        $this->assertSame('Tokyo', $dto->person->address->city);
    }

    public function test_deeply_nested_missing_field_dot_notation(): void
    {
        $json = json_encode([
            'label'  => 'Deep',
            'person' => [
                'name'    => 'Carol',
                'age'     => 25,
                'address' => ['city' => 'Tokyo'],
            ],
        ]);
        try {
            $this->resolve('asDeep', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('person.address.zip', $e->getErrors());
        }
    }

    public function test_nested_dto_wrong_type_error(): void
    {
        // address is a string instead of object
        $json = '{"name":"Bob","age":30,"address":"wrong"}';
        try {
            $this->resolve('asNested', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('address', $errors);
            $this->assertStringContainsString('must be an object', $errors['address'][0]);
        }
    }

    // ── multiple errors collected at once ─────────────────────────────────────

    public function test_multiple_missing_fields_all_reported(): void
    {
        try {
            $this->resolve('asDto', $this->makeRequest('{}'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('title', $errors);
            $this->assertArrayHasKey('amount', $errors);
        }
    }

    // ── variadic #[RequestBody] ───────────────────────────────────────────────

    public function test_variadic_empty_array_returns_no_items(): void
    {
        $result = $this->resolve('asVariadic', $this->makeRequest('[]'));
        $this->assertSame([], $result);
    }

    public function test_variadic_single_item_hydrated(): void
    {
        $json = '[{"title":"Order","amount":10}]';
        $result = $this->resolve('asVariadic', $this->makeRequest($json));
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Body_VariadicItemDto::class, $result[0]);
        $this->assertSame('Order', $result[0]->title);
        $this->assertSame(10, $result[0]->amount);
    }

    public function test_variadic_multiple_items_hydrated(): void
    {
        $json = '[{"title":"A","amount":1},{"title":"B","amount":2}]';
        $result = $this->resolve('asVariadic', $this->makeRequest($json));
        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]->title);
        $this->assertSame('B', $result[1]->title);
    }

    public function test_variadic_non_array_json_throws(): void
    {
        $this->expectException(\Flytachi\Winter\Kernel\Http\Request\RequestException::class);
        $this->resolve('asVariadic', $this->makeRequest('{"title":"X","amount":1}'));
    }

    public function test_variadic_structural_error_uses_index_key(): void
    {
        try {
            $this->resolve('asVariadic', $this->makeRequest('[{"title":"A","amount":1},{"title":"B"}]'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('[1].amount', $e->getErrors());
        }
    }

    public function test_variadic_errors_from_multiple_elements(): void
    {
        try {
            $this->resolve('asVariadic', $this->makeRequest('[{"amount":1},{"title":"B"}]'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('[0].title', $errors);
            $this->assertArrayHasKey('[1].amount', $errors);
        }
    }

    public function test_variadic_valid_constraint_violation_with_index_key(): void
    {
        try {
            $this->resolve('asVariadicValid', $this->makeRequest('[{"title":"A","amount":1},{"title":"B","amount":-1}]'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('[1].amount', $e->getErrors());
        }
    }

    public function test_variadic_valid_passes_when_all_valid(): void
    {
        $json = '[{"title":"A","amount":1},{"title":"B","amount":5}]';
        $result = $this->resolve('asVariadicValid', $this->makeRequest($json));
        $this->assertCount(2, $result);
    }

    // ── nested DTO + #[Valid] constraint cascade ───────────────────────────────

    public function test_nested_valid_constraint_on_outer_field(): void
    {
        $json = '{"name":"","address":{"city":"Berlin","zip":"10115"}}';
        try {
            $this->resolve('asConstrainedNested', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->getErrors());
        }
    }

    public function test_nested_valid_constraint_on_inner_field(): void
    {
        $json = '{"name":"Alice","address":{"city":"","zip":"10115"}}';
        try {
            $this->resolve('asConstrainedNested', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('address.city', $e->getErrors());
        }
    }

    public function test_nested_valid_outer_and_inner_errors_both_reported(): void
    {
        $json = '{"name":"","address":{"city":"","zip":"10115"}}';
        try {
            $this->resolve('asConstrainedNested', $this->makeRequest($json));
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
        [$dto] = $this->resolve('asConstrainedNested', $this->makeRequest($json));
        $this->assertSame('Alice', $dto->name);
        $this->assertSame('Berlin', $dto->address->city);
    }

    // ── #[Valid] constraint integration ───────────────────────────────────────

    public function test_valid_passes_when_constraints_satisfied(): void
    {
        $json = '{"title":"Test","amount":5}';
        [$dto] = $this->resolve('asValidated', $this->makeRequest($json));
        $this->assertInstanceOf(Body_ValidatedDto::class, $dto);
    }

    public function test_valid_fails_on_constraint_violation(): void
    {
        $json = '{"title":"Test","amount":0}';
        try {
            $this->resolve('asValidated', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->getErrors());
        }
    }

    public function test_valid_fails_on_blank_string(): void
    {
        $json = '{"title":"   ","amount":10}';
        try {
            $this->resolve('asValidated', $this->makeRequest($json));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->getErrors());
        }
    }

    // ── field mode ────────────────────────────────────────────────────────────

    public function test_field_extracted_and_cast(): void
    {
        [$age] = $this->resolve('fieldInt', $this->makeRequest('{"age":"42"}'));
        $this->assertSame(42, $age);
    }

    public function test_field_respects_content_type_xml(): void
    {
        $xml = '<root><name>Jonathan</name></root>';
        [$name] = $this->resolve('fieldString', $this->makeRequest($xml, 'application/xml'));
        $this->assertSame('Jonathan', $name);
    }

    public function test_field_dot_notation_nested(): void
    {
        [$minPrice] = $this->resolve('fieldNested', $this->makeRequest('{"filter":{"minPrice":15}}'));
        $this->assertSame(15, $minPrice);
    }

    public function test_field_missing_required_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Required Body field 'name' is missing");
        $this->resolve('fieldString', $this->makeRequest('{"other":1}'));
    }

    public function test_field_optional_returns_null(): void
    {
        [$name] = $this->resolve('fieldOptional', $this->makeRequest('{"x":1}'));
        $this->assertNull($name);
    }

    public function test_field_constraint_fires_without_valid(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('fieldString', $this->makeRequest('{"name":"Jo"}')); // < Size(5, 40)
    }
}
