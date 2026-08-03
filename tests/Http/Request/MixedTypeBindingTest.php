<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Http\Request;

use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\ParameterResolver;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\Kernel\Http\Request\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// ── Fixtures ──────────────────────────────────────────────────────────────────

/** Mirrors a DataGrid filter instruction: the value's shape depends on the operator. */
readonly class MixedFilterItem
{
    public function __construct(
        public string $field = '',
        public mixed $value = null,
    ) {
    }
}

readonly class MixedIterableDto
{
    public function __construct(
        public iterable $items = [],
    ) {
    }
}

class MixedTypeFixture
{
    public function filter(#[RequestJson] MixedFilterItem $item): MixedFilterItem
    {
        return $item;
    }

    public function iterableDto(#[RequestJson] MixedIterableDto $dto): MixedIterableDto
    {
        return $dto;
    }
}

/**
 * `mixed` and `iterable` accept an array, and the binder has to agree.
 *
 * The array guard exists to turn `?field[]=a&field[]=b` bound to an `int` into a clear
 * 400 instead of a cast to `Array`. It compared the declared type against the single
 * string `'array'`, so every other type name — including the two that *do* accept an
 * array — was refused. A DataGrid filter is the case that surfaced it: `value` is
 * `mixed` because `isAnyOf` sends a list while `contains` sends a string.
 */
final class MixedTypeBindingTest extends TestCase
{
    private HttpResponse $response;

    protected function setUp(): void
    {
        $this->response = $this->createStub(HttpResponse::class);
    }

    private function resolve(string $method, string $rawBody): array
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getRawBody')->willReturn($rawBody);
        $request->method('getQueryParams')->willReturn([]);

        return ParameterResolver::resolve(
            new ReflectionMethod(MixedTypeFixture::class, $method),
            $request,
            $this->response,
            [],
        );
    }

    public function test_a_mixed_property_accepts_an_array(): void
    {
        $args = $this->resolve('filter', '{"field":"status","value":["draft","sent"]}');

        self::assertSame(['draft', 'sent'], $args[0]->value);
    }

    public function test_a_mixed_property_accepts_a_nested_object(): void
    {
        $args = $this->resolve('filter', '{"field":"range","value":{"from":1,"to":9}}');

        self::assertSame(['from' => 1, 'to' => 9], $args[0]->value);
    }

    /**
     * `mixed` accepts every kind and converts none of them: the value must arrive with
     * the type JSON gave it. Casting here would be a guess — the whole point of the
     * declaration is that the shape is decided elsewhere.
     *
     * @return array<string, array{string, mixed}>
     */
    public static function everyKind(): array
    {
        return [
            'string' => ['"john"', 'john'],
            'int' => ['42', 42],
            'negative int' => ['-7', -7],
            'float' => ['3.14', 3.14],
            'bool true' => ['true', true],
            'bool false' => ['false', false],
            'null' => ['null', null],
            'empty string' => ['""', ''],
            'zero' => ['0', 0],
            'numeric string stays a string' => ['"42"', '42'],
            'list' => ['[1,2]', [1, 2]],
            'nested object' => ['{"a":1}', ['a' => 1]],
            'empty list' => ['[]', []],
        ];
    }

    #[DataProvider('everyKind')]
    public function test_a_mixed_property_takes_every_kind_unchanged(string $json, mixed $expected): void
    {
        $args = $this->resolve('filter', '{"field":"f","value":' . $json . '}');

        self::assertSame($expected, $args[0]->value);
    }

    public function test_an_iterable_property_accepts_an_array(): void
    {
        $args = $this->resolve('iterableDto', '{"items":[1,2,3]}');

        self::assertSame([1, 2, 3], $args[0]->items);
    }

    /**
     * The guard must keep doing its job: an array reaching a scalar is still a client
     * error, and reporting it beats casting the value to the string "Array".
     *
     * Hydrating a DTO collects such refusals into a validation report rather than
     * failing on the first one, so the caller sees every bad field at once — hence
     * ValidationException here and RequestException on a bare parameter.
     */
    public function test_an_array_reaching_a_string_is_still_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->resolve('filter', '{"field":["a","b"],"value":null}');
    }
}
