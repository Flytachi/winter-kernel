<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestXml;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\Size;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use Flytachi\Winter\K2\Http\Request\RequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixture DTOs ──────────────────────────────────────────────────────────────

class Xml_CoordDto
{
    public function __construct(
        public readonly string $lat,
        public readonly string $lon,
    ) {}
}

class Xml_LocationDto
{
    public function __construct(
        public readonly string    $name,
        public readonly Xml_CoordDto $coords,
    ) {}
}

class Xml_SimpleDto
{
    public function __construct(
        public readonly string $city,
        public readonly string $country,
    ) {}
}

class Xml_ValidatedDto
{
    public function __construct(
        #[Required] #[NotBlank]
        public readonly string $code,
    ) {}
}

class Xml_VariadicItemDto
{
    public function __construct(
        public readonly string $name,
        #[Positive]
        public readonly int    $qty,
    ) {}
}

class Xml_ConstrainedNestedDto
{
    public function __construct(
        #[NotBlank]
        public readonly string    $title,
        public readonly Xml_SimpleDto $location,
    ) {}
}

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestXmlFixture
{
    public function asArray(#[RequestXml] array $body): void {}
    public function asStdClass(#[RequestXml] \stdClass $body): void {}
    public function asDto(#[RequestXml] Xml_SimpleDto $body): void {}
    public function asNested(#[RequestXml] Xml_LocationDto $body): void {}
    public function asValidated(#[Valid] #[RequestXml] Xml_ValidatedDto $body): void {}
    public function asVariadic(#[RequestXml] Xml_VariadicItemDto ...$items): void {}
    public function asVariadicValid(#[RequestXml, Valid] Xml_VariadicItemDto ...$items): void {}
    public function asConstrainedNested(#[Valid] #[RequestXml] Xml_ConstrainedNestedDto $body): void {}

    // ── field mode ──
    public function fieldString(#[RequestXml(field: 'name'), Size(5, 40)] string $name): void {}
    public function fieldFloat(#[RequestXml(field: 'coords.lat')] float $lat): void {}
    public function fieldOptional(#[RequestXml(field: 'name')] ?string $name = null): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class RequestXmlTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function makeRequest(string $xml): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getRawBody')->willReturn($xml);
        $req->method('getQueryParams')->willReturn([]);
        return $req;
    }

    private function resolve(string $method, string $xml): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(RequestXmlFixture::class, $method),
            $this->makeRequest($xml),
            $this->response,
            [],
        );
    }

    // ── array ─────────────────────────────────────────────────────────────────

    public function test_array_parsed_from_xml(): void
    {
        $xml = '<root><city>Moscow</city><country>Russia</country></root>';
        [$body] = $this->resolve('asArray', $xml);
        $this->assertSame('Moscow', $body['city']);
        $this->assertSame('Russia', $body['country']);
    }

    public function test_array_invalid_xml_returns_empty(): void
    {
        [$body] = $this->resolve('asArray', 'not-xml-at-all!!!');
        $this->assertSame([], $body);
    }

    public function test_array_empty_body_returns_empty(): void
    {
        [$body] = $this->resolve('asArray', '');
        $this->assertSame([], $body);
    }

    // ── stdClass ──────────────────────────────────────────────────────────────

    public function test_stdclass_from_xml(): void
    {
        $xml = '<root><name>Alice</name><age>30</age></root>';
        [$body] = $this->resolve('asStdClass', $xml);
        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame('Alice', $body->name);
        $this->assertSame('30', $body->age);
    }

    public function test_stdclass_invalid_xml_returns_empty_object(): void
    {
        [$body] = $this->resolve('asStdClass', '');
        $this->assertInstanceOf(\stdClass::class, $body);
    }

    // ── DTO hydration ─────────────────────────────────────────────────────────

    public function test_dto_hydrated_from_xml(): void
    {
        $xml = '<root><city>Berlin</city><country>Germany</country></root>';
        [$dto] = $this->resolve('asDto', $xml);
        $this->assertInstanceOf(Xml_SimpleDto::class, $dto);
        $this->assertSame('Berlin', $dto->city);
        $this->assertSame('Germany', $dto->country);
    }

    public function test_dto_missing_field_throws(): void
    {
        $xml = '<root><city>Berlin</city></root>';
        $this->expectException(ValidationException::class);
        $this->resolve('asDto', $xml);
    }

    // ── nested DTO ────────────────────────────────────────────────────────────

    public function test_nested_dto_hydrated_from_xml(): void
    {
        $xml = '<root><name>Office</name><coords><lat>55.75</lat><lon>37.61</lon></coords></root>';
        [$dto] = $this->resolve('asNested', $xml);
        $this->assertInstanceOf(Xml_LocationDto::class, $dto);
        $this->assertSame('Office', $dto->name);
        $this->assertInstanceOf(Xml_CoordDto::class, $dto->coords);
        $this->assertSame('55.75', $dto->coords->lat);
        $this->assertSame('37.61', $dto->coords->lon);
    }

    public function test_nested_dto_missing_child_field_dot_path(): void
    {
        $xml = '<root><name>Office</name><coords><lat>55.75</lat></coords></root>';
        try {
            $this->resolve('asNested', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('coords.lon', $e->getErrors());
        }
    }

    // ── multiple missing fields — all reported ────────────────────────────────

    public function test_multiple_missing_fields_all_reported(): void
    {
        try {
            $this->resolve('asDto', '<root></root>');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('city', $errors);
            $this->assertArrayHasKey('country', $errors);
        }
    }

    // ── variadic #[RequestXml] ────────────────────────────────────────────────

    public function test_variadic_single_element_from_xml(): void
    {
        // Single XML doc → non-list map → wrapped into one-element variadic
        $xml = '<root><name>Widget</name><qty>3</qty></root>';
        $result = $this->resolve('asVariadic', $xml);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Xml_VariadicItemDto::class, $result[0]);
        $this->assertSame('Widget', $result[0]->name);
        $this->assertSame(3, $result[0]->qty);
    }

    public function test_variadic_structural_error_uses_index_key(): void
    {
        $xml = '<root><qty>3</qty></root>';
        try {
            $this->resolve('asVariadic', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('[0].name', $e->getErrors());
        }
    }

    public function test_variadic_valid_constraint_violation(): void
    {
        $xml = '<root><name>Widget</name><qty>-1</qty></root>';
        try {
            $this->resolve('asVariadicValid', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('[0].qty', $e->getErrors());
        }
    }

    public function test_variadic_valid_passes(): void
    {
        $xml = '<root><name>Widget</name><qty>5</qty></root>';
        $result = $this->resolve('asVariadicValid', $xml);
        $this->assertCount(1, $result);
    }

    // ── nested DTO + #[Valid] constraint cascade ───────────────────────────────

    public function test_nested_valid_constraint_on_outer_field(): void
    {
        $xml = '<root><title></title><location><city>Berlin</city><country>Germany</country></location></root>';
        try {
            $this->resolve('asConstrainedNested', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->getErrors());
        }
    }

    public function test_nested_valid_constraint_on_inner_field(): void
    {
        $xml = '<root><title>Report</title><location><city></city><country>Germany</country></location></root>';
        try {
            $this->resolve('asConstrainedNested', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('location.city', $e->getErrors());
        }
    }

    public function test_nested_valid_passes(): void
    {
        $xml = '<root><title>Report</title><location><city>Berlin</city><country>Germany</country></location></root>';
        [$dto] = $this->resolve('asConstrainedNested', $xml);
        $this->assertSame('Report', $dto->title);
        $this->assertSame('Berlin', $dto->location->city);
    }

    // ── #[Valid] integration ──────────────────────────────────────────────────

    public function test_valid_passes(): void
    {
        $xml = '<root><code>ABC123</code></root>';
        [$dto] = $this->resolve('asValidated', $xml);
        $this->assertSame('ABC123', $dto->code);
    }

    public function test_valid_blank_code_fails(): void
    {
        $xml = '<root><code>   </code></root>';
        try {
            $this->resolve('asValidated', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->getErrors());
        }
    }

    // ── field mode ────────────────────────────────────────────────────────────

    public function test_field_extracted(): void
    {
        [$name] = $this->resolve('fieldString', '<root><name>Jonathan</name></root>');
        $this->assertSame('Jonathan', $name);
    }

    public function test_field_float_cast_nested(): void
    {
        // XML text content arrives as a string — cast applies; dot-notation walks nesting
        [$lat] = $this->resolve('fieldFloat', '<root><coords><lat>1.5</lat></coords></root>');
        $this->assertSame(1.5, $lat);
    }

    public function test_field_missing_required_throws(): void
    {
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage("Required XML field 'name' is missing");
        $this->resolve('fieldString', '<root><other>x</other></root>');
    }

    public function test_field_optional_returns_null(): void
    {
        [$name] = $this->resolve('fieldOptional', '<root><other>x</other></root>');
        $this->assertNull($name);
    }

    public function test_field_constraint_fires_without_valid(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('fieldString', '<root><name>Jo</name></root>'); // < Size(5, 40)
    }
}
