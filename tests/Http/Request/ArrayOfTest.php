<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Tests\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\ParameterResolver;
use Flytachi\Winter\K2\Http\Request\Validation\ArrayOf;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestXml;
use Flytachi\Winter\K2\Http\Request\Validation\Min;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\Size;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Enums ─────────────────────────────────────────────────────────────────────

enum AO_Priority: int
{
    case LOW  = 1;
    case HIGH = 2;
}

// ── Element DTOs ──────────────────────────────────────────────────────────────

/** Basic element: required string + optional int */
class AO_ItemDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $name,
        #[Min(0)]
        public readonly int    $quantity = 0,
    ) {}
}

/** Element with enum, datetime, and nullable optional field */
class AO_RichItemDto
{
    public function __construct(
        public readonly string              $sku,
        public readonly AO_Priority         $priority,
        public readonly ?\DateTimeImmutable $expires  = null,
        public readonly ?string             $note     = null,
    ) {}
}

/** Inner element for deep-nesting tests */
class AO_TagDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $label,
        #[Min(0)]
        public readonly float  $weight = 1.0,
    ) {}
}

/** Element that itself has an #[ArrayOf] child */
class AO_LineDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $product,
        #[Size(min: 1)]
        #[ArrayOf(AO_TagDto::class)]
        public readonly array  $tags,
    ) {}
}

// ── Parent DTOs ───────────────────────────────────────────────────────────────

/** items has a default → absent from payload uses [] */
class AO_OrderDto
{
    public function __construct(
        public readonly string $title,
        #[ArrayOf(AO_ItemDto::class)]
        public readonly array  $items = [],
    ) {}
}

/** items has no default → required */
class AO_StrictOrderDto
{
    public function __construct(
        #[ArrayOf(AO_ItemDto::class)]
        public readonly array $items,
    ) {}
}

/** Size + ArrayOf combined */
class AO_BoundedDto
{
    public function __construct(
        #[Size(min: 1, max: 5)]
        #[ArrayOf(AO_ItemDto::class)]
        public readonly array $items,
    ) {}
}

/** Enum + datetime element array */
class AO_RichOrderDto
{
    public function __construct(
        public readonly string $ref,
        #[ArrayOf(AO_RichItemDto::class)]
        public readonly array  $lines,
    ) {}
}

/** Two-level nesting: order → lines → tags */
class AO_DeepOrderDto
{
    public function __construct(
        public readonly int   $id,
        #[ArrayOf(AO_LineDto::class)]
        public readonly array $lines,
    ) {}
}

/** Outer DTO has both a plain field and an ArrayOf — mixed error sourcing */
class AO_InvoiceDto
{
    public function __construct(
        #[NotBlank]
        public readonly string $number,
        #[Size(min: 1)]
        #[ArrayOf(AO_ItemDto::class)]
        public readonly array  $items,
    ) {}
}

// ── Fixture controller ────────────────────────────────────────────────────────

class ArrayOfFixture
{
    public function order(#[RequestJson] AO_OrderDto $body): void {}
    public function strict(#[RequestJson] AO_StrictOrderDto $body): void {}
    public function bounded(#[RequestJson] AO_BoundedDto $body): void {}
    public function rich(#[RequestJson] AO_RichOrderDto $body): void {}
    public function deep(#[RequestJson] AO_DeepOrderDto $body): void {}
    public function invoice(#[RequestJson] AO_InvoiceDto $body): void {}
    public function formOrder(#[RequestForm] AO_OrderDto $body): void {}
    public function formBounded(#[RequestForm] AO_BoundedDto $body): void {}
    public function xmlOrder(#[RequestXml] AO_OrderDto $body): void {}
    public function xmlBounded(#[RequestXml] AO_BoundedDto $body): void {}
    public function xmlDeep(#[RequestXml] AO_DeepOrderDto $body): void {}
}

// ─────────────────────────────────────────────────────────────────────────────

class ArrayOfTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function makeJsonRequest(string $raw): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getRawBody')->willReturn($raw);
        $req->method('getQueryParams')->willReturn([]);
        return $req;
    }

    private function makeFormRequest(array $data): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getParsedBody')->willReturn($data);
        $req->method('getQueryParams')->willReturn([]);
        $req->method('getRawBody')->willReturn('');
        return $req;
    }

    private function makeXmlRequest(string $xml): HttpRequest
    {
        $req = $this->createStub(HttpRequest::class);
        $req->method('getRawBody')->willReturn($xml);
        $req->method('getQueryParams')->willReturn([]);
        return $req;
    }

    private function resolveXml(string $method, string $xml): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(ArrayOfFixture::class, $method),
            $this->makeXmlRequest($xml),
            $this->response,
            [],
        );
    }

    private function resolveForm(string $method, array $data): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(ArrayOfFixture::class, $method),
            $this->makeFormRequest($data),
            $this->response,
            [],
        );
    }

    private function resolve(string $method, string $raw): array
    {
        return ParameterResolver::resolve(
            new ReflectionMethod(ArrayOfFixture::class, $method),
            $this->makeJsonRequest($raw),
            $this->response,
            [],
        );
    }

    // ── Happy path — basic ────────────────────────────────────────────────────

    public function test_empty_array_gives_empty_collection(): void
    {
        [$dto] = $this->resolve('order', '{"title":"T","items":[]}');
        $this->assertSame([], $dto->items);
    }

    public function test_absent_field_uses_default(): void
    {
        [$dto] = $this->resolve('order', '{"title":"T"}');
        $this->assertSame([], $dto->items);
    }

    public function test_single_element_hydrated(): void
    {
        [$dto] = $this->resolve('order', '{"title":"T","items":[{"name":"Widget","quantity":3}]}');
        $this->assertCount(1, $dto->items);
        $this->assertInstanceOf(AO_ItemDto::class, $dto->items[0]);
        $this->assertSame('Widget', $dto->items[0]->name);
        $this->assertSame(3, $dto->items[0]->quantity);
    }

    public function test_multiple_elements_all_hydrated(): void
    {
        [$dto] = $this->resolve('order', '{"title":"T","items":[{"name":"A","quantity":1},{"name":"B","quantity":2},{"name":"C"}]}');
        $this->assertCount(3, $dto->items);
        $this->assertSame('A', $dto->items[0]->name);
        $this->assertSame('B', $dto->items[1]->name);
        $this->assertSame('C', $dto->items[2]->name);
    }

    public function test_element_default_value_used_when_field_absent(): void
    {
        [$dto] = $this->resolve('order', '{"title":"T","items":[{"name":"X"}]}');
        $this->assertSame(0, $dto->items[0]->quantity);
    }

    // ── Happy path — rich types (enum, datetime, nullable) ────────────────────

    public function test_element_with_int_backed_enum(): void
    {
        $json = '{"ref":"R","lines":[{"sku":"SKU-1","priority":1}]}';
        [$dto] = $this->resolve('rich', $json);
        $this->assertSame(AO_Priority::LOW, $dto->lines[0]->priority);
    }

    public function test_element_with_high_priority_enum(): void
    {
        $json = '{"ref":"R","lines":[{"sku":"SKU-1","priority":2}]}';
        [$dto] = $this->resolve('rich', $json);
        $this->assertSame(AO_Priority::HIGH, $dto->lines[0]->priority);
    }

    public function test_element_with_datetime_field(): void
    {
        $json = '{"ref":"R","lines":[{"sku":"SKU-1","priority":1,"expires":"2025-12-31"}]}';
        [$dto] = $this->resolve('rich', $json);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->lines[0]->expires);
        $this->assertSame('2025-12-31', $dto->lines[0]->expires->format('Y-m-d'));
    }

    public function test_element_nullable_field_absent_is_null(): void
    {
        $json = '{"ref":"R","lines":[{"sku":"SKU-1","priority":1}]}';
        [$dto] = $this->resolve('rich', $json);
        $this->assertNull($dto->lines[0]->expires);
        $this->assertNull($dto->lines[0]->note);
    }

    public function test_element_nullable_field_present(): void
    {
        $json = '{"ref":"R","lines":[{"sku":"SKU-1","priority":1,"note":"hello"}]}';
        [$dto] = $this->resolve('rich', $json);
        $this->assertSame('hello', $dto->lines[0]->note);
    }

    // ── Structural errors ─────────────────────────────────────────────────────

    public function test_array_field_absent_without_default_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolve('strict', '{}');
    }

    public function test_scalar_as_element_reports_error_with_index_key(): void
    {
        $json = '{"title":"T","items":["not-an-object",{"name":"B"}]}';
        try {
            $this->resolve('order', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items[0]', $errors);
            $this->assertArrayNotHasKey('items[1]', $errors);
        }
    }

    public function test_missing_required_field_in_element(): void
    {
        try {
            $this->resolve('order', '{"title":"T","items":[{"quantity":5}]}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items[0].name', $e->getErrors());
        }
    }

    public function test_missing_field_in_multiple_elements_all_reported(): void
    {
        $json = '{"title":"T","items":[{"quantity":1},{"quantity":2},{"quantity":3}]}';
        try {
            $this->resolve('order', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items[0].name', $errors);
            $this->assertArrayHasKey('items[1].name', $errors);
            $this->assertArrayHasKey('items[2].name', $errors);
        }
    }

    public function test_invalid_enum_value_in_element(): void
    {
        $json = '{"ref":"R","lines":[{"sku":"S","priority":99}]}';
        try {
            $this->resolve('rich', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines[0].priority', $e->getErrors());
        }
    }

    // ── Constraint errors ─────────────────────────────────────────────────────

    public function test_notblank_violation_in_element(): void
    {
        try {
            $this->resolve('order', '{"title":"T","items":[{"name":"  ","quantity":1}]}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items[0].name', $e->getErrors());
        }
    }

    public function test_min_violation_in_element(): void
    {
        try {
            $this->resolve('order', '{"title":"T","items":[{"name":"X","quantity":-1}]}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items[0].quantity', $e->getErrors());
        }
    }

    public function test_multiple_constraint_violations_in_same_element(): void
    {
        // blank name + negative quantity — both reported for element[0]
        try {
            $this->resolve('order', '{"title":"T","items":[{"name":"","quantity":-5}]}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items[0].name', $errors);
            $this->assertArrayHasKey('items[0].quantity', $errors);
        }
    }

    // ── Mixed: structural + constraint across elements ─────────────────────────

    public function test_structural_error_in_one_element_constraint_in_another(): void
    {
        // element[0] missing name (structural), element[1] blank name (constraint)
        $json = '{"title":"T","items":[{"quantity":1},{"name":"  ","quantity":2}]}';
        try {
            $this->resolve('order', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items[0].name', $errors);
            $this->assertArrayHasKey('items[1].name', $errors);
        }
    }

    public function test_outer_field_error_and_inner_element_error_both_reported(): void
    {
        // outer: number blank; inner: item[0] name missing
        $json = '{"number":"","items":[{"quantity":1}]}';
        try {
            $this->resolve('invoice', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('number', $errors);
            $this->assertArrayHasKey('items[0].name', $errors);
        }
    }

    public function test_valid_and_invalid_elements_errors_only_for_invalid(): void
    {
        // element[0] valid, element[1] invalid (missing name)
        $json = '{"title":"T","items":[{"name":"Good","quantity":1},{"quantity":2}]}';
        try {
            $this->resolve('order', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayNotHasKey('items[0].name', $errors);
            $this->assertArrayHasKey('items[1].name', $errors);
        }
    }

    // ── #[Size] on the collection ─────────────────────────────────────────────

    public function test_size_min_empty_array_fails(): void
    {
        try {
            $this->resolve('bounded', '{"items":[]}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items', $errors);
            $this->assertStringContainsString('1', $errors['items'][0]);
        }
    }

    public function test_size_max_exceeded_fails(): void
    {
        $items = array_fill(0, 6, ['name' => 'X']);
        try {
            $this->resolve('bounded', json_encode(['items' => $items]));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
        }
    }

    public function test_size_at_min_boundary_passes(): void
    {
        [$dto] = $this->resolve('bounded', '{"items":[{"name":"X"}]}');
        $this->assertCount(1, $dto->items);
    }

    public function test_size_at_max_boundary_passes(): void
    {
        $items = array_fill(0, 5, ['name' => 'X']);
        [$dto] = $this->resolve('bounded', json_encode(['items' => $items]));
        $this->assertCount(5, $dto->items);
    }

    public function test_size_invoice_empty_items_fails(): void
    {
        try {
            $this->resolve('invoice', '{"number":"INV-001","items":[]}');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
        }
    }

    // ── Deep nesting: line → tags ─────────────────────────────────────────────

    public function test_deep_nesting_hydrated_correctly(): void
    {
        $json = '{"id":1,"lines":[{"product":"P1","tags":[{"label":"hot","weight":1.5}]}]}';
        [$dto] = $this->resolve('deep', $json);
        $this->assertCount(1, $dto->lines);
        $this->assertInstanceOf(AO_LineDto::class, $dto->lines[0]);
        $this->assertCount(1, $dto->lines[0]->tags);
        $this->assertInstanceOf(AO_TagDto::class, $dto->lines[0]->tags[0]);
        $this->assertSame('hot', $dto->lines[0]->tags[0]->label);
        $this->assertSame(1.5, $dto->lines[0]->tags[0]->weight);
    }

    public function test_deep_nesting_error_key_format(): void
    {
        // lines[1].tags[0].label is blank
        $json = '{"id":1,"lines":[{"product":"P1","tags":[{"label":"ok"}]},{"product":"P2","tags":[{"label":""}]}]}';
        try {
            $this->resolve('deep', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines[1].tags[0].label', $e->getErrors());
        }
    }

    public function test_deep_nesting_size_on_inner_array_reported(): void
    {
        // lines[0] has empty tags → Size(min:1) fails on tags
        $json = '{"id":1,"lines":[{"product":"P1","tags":[]}]}';
        try {
            $this->resolve('deep', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines[0].tags', $e->getErrors());
        }
    }

    public function test_deep_nesting_outer_and_inner_errors_both_reported(): void
    {
        // lines[0]: product blank + tags empty
        $json = '{"id":1,"lines":[{"product":"","tags":[]}]}';
        try {
            $this->resolve('deep', $json);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('lines[0].product', $errors);
            $this->assertArrayHasKey('lines[0].tags', $errors);
        }
    }

    // ── RequestForm source ────────────────────────────────────────────────────

    public function test_array_of_works_with_request_form(): void
    {
        $req = ParameterResolver::resolve(
            new ReflectionMethod(ArrayOfFixture::class, 'formOrder'),
            $this->makeFormRequest([
                'title' => 'FormOrder',
                'items' => [
                    ['name' => 'Widget', 'quantity' => '2'],
                    ['name' => 'Gadget'],
                ],
            ]),
            $this->response,
            [],
        );

        [$dto] = $req;
        $this->assertInstanceOf(AO_OrderDto::class, $dto);
        $this->assertCount(2, $dto->items);
        $this->assertSame('Widget', $dto->items[0]->name);
        $this->assertSame(2, $dto->items[0]->quantity);
        $this->assertSame('Gadget', $dto->items[1]->name);
    }

    public function test_array_of_form_element_error_reported(): void
    {
        try {
            $this->resolveForm('formOrder', ['title' => 'T', 'items' => [['quantity' => '1']]]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items[0].name', $e->getErrors());
        }
    }

    public function test_form_size_empty_fails(): void
    {
        try {
            $this->resolveForm('formBounded', ['items' => []]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
        }
    }

    public function test_form_size_max_exceeded_fails(): void
    {
        $items = array_fill(0, 6, ['name' => 'X']);
        try {
            $this->resolveForm('formBounded', ['items' => $items]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
        }
    }

    // ── XML source ────────────────────────────────────────────────────────────

    public function test_xml_multiple_items_hydrated(): void
    {
        $xml = '<root><title>T</title><items><item><name>Widget</name><quantity>3</quantity></item><item><name>Gadget</name><quantity>1</quantity></item></items></root>';
        [$dto] = $this->resolveXml('xmlOrder', $xml);
        $this->assertInstanceOf(AO_OrderDto::class, $dto);
        $this->assertCount(2, $dto->items);
        $this->assertInstanceOf(AO_ItemDto::class, $dto->items[0]);
        $this->assertSame('Widget', $dto->items[0]->name);
        $this->assertSame(3, $dto->items[0]->quantity);
        $this->assertSame('Gadget', $dto->items[1]->name);
    }

    public function test_xml_single_item_unwrapped_correctly(): void
    {
        // SimpleXML collapses a single child element into an object, not a list
        $xml = '<root><title>T</title><items><item><name>OnlyOne</name><quantity>5</quantity></item></items></root>';
        [$dto] = $this->resolveXml('xmlOrder', $xml);
        $this->assertCount(1, $dto->items);
        $this->assertSame('OnlyOne', $dto->items[0]->name);
        $this->assertSame(5, $dto->items[0]->quantity);
    }

    public function test_xml_element_default_value_applied(): void
    {
        $xml = '<root><title>T</title><items><item><name>X</name></item></items></root>';
        [$dto] = $this->resolveXml('xmlOrder', $xml);
        $this->assertSame(0, $dto->items[0]->quantity);
    }

    public function test_xml_missing_required_element_field_throws(): void
    {
        $xml = '<root><title>T</title><items><item><quantity>1</quantity></item></items></root>';
        try {
            $this->resolveXml('xmlOrder', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items[0].name', $e->getErrors());
        }
    }

    public function test_xml_constraint_violation_in_element_throws(): void
    {
        $xml = '<root><title>T</title><items><item><name></name><quantity>1</quantity></item></items></root>';
        try {
            $this->resolveXml('xmlOrder', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items[0].name', $e->getErrors());
        }
    }

    public function test_xml_multiple_items_all_errors_reported(): void
    {
        $xml = '<root><title>T</title><items><item><quantity>1</quantity></item><item><quantity>2</quantity></item></items></root>';
        try {
            $this->resolveXml('xmlOrder', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('items[0].name', $errors);
            $this->assertArrayHasKey('items[1].name', $errors);
        }
    }

    public function test_xml_size_min_empty_fails(): void
    {
        $xml = '<root><items></items></root>';
        try {
            $this->resolveXml('xmlBounded', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
        }
    }

    public function test_xml_size_max_exceeded_fails(): void
    {
        $items = str_repeat('<item><name>X</name></item>', 6);
        $xml   = "<root><items>{$items}</items></root>";
        try {
            $this->resolveXml('xmlBounded', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->getErrors());
        }
    }

    public function test_xml_deep_nesting_hydrated(): void
    {
        $xml = '<root><id>1</id><lines><line><product>P1</product><tags><tag><label>hot</label><weight>1.5</weight></tag></tags></line></lines></root>';
        [$dto] = $this->resolveXml('xmlDeep', $xml);
        $this->assertCount(1, $dto->lines);
        $this->assertSame('P1', $dto->lines[0]->product);
        $this->assertCount(1, $dto->lines[0]->tags);
        $this->assertSame('hot', $dto->lines[0]->tags[0]->label);
    }

    public function test_xml_deep_nesting_error_path(): void
    {
        // lines[0].tags[0].label is empty
        $xml = '<root><id>1</id><lines><line><product>P1</product><tags><tag><label></label></tag></tags></line></lines></root>';
        try {
            $this->resolveXml('xmlDeep', $xml);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines[0].tags[0].label', $e->getErrors());
        }
    }
}
