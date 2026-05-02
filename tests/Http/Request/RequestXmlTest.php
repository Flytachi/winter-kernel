<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestXml;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
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

// ── Fixture controller ────────────────────────────────────────────────────────

class RequestXmlFixture
{
    public function asArray(#[RequestXml] array $body): void {}
    public function asStdClass(#[RequestXml] \stdClass $body): void {}
    public function asDto(#[RequestXml] Xml_SimpleDto $body): void {}
    public function asNested(#[RequestXml] Xml_LocationDto $body): void {}
    public function asValidated(#[Valid] #[RequestXml] Xml_ValidatedDto $body): void {}
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
}
