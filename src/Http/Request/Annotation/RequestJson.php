<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

use Attribute;

/**
 * Forces the request body to be parsed as JSON, regardless of Content-Type.
 *
 * Supported types:
 *   - array        — json_decode($raw, true) or [] on parse failure
 *   - stdClass     — json_decode($raw) or new stdClass() on parse failure
 *   - SomeDto      — hydrated from decoded array via constructor (reflection)
 *   - SomeDto ...$v — variadic: JSON array expected, each element → one DTO instance;
 *                   a JSON object (not a list) throws 400
 *
 * Nested class-typed constructor parameters are hydrated recursively.
 * Error keys use dot-notation for nested paths: "filter.minPrice".
 * Add #[Valid] to trigger #[Constraint] validation on DTO fields after hydration.
 *
 * Single-field mode — pass `field` to pull one value out of the body instead of
 * hydrating the whole payload. The extracted value is cast to the parameter type
 * (scalar, enum, DateTime, Number, array, stdClass, or DTO), and behaves like any
 * other scalar source: required by default, optional via `?T` or a default value,
 * and #[Constraint] attributes fire automatically — no #[Valid] needed.
 * The field path supports dot-notation for nested access ('filter.minPrice').
 *
 * Use #[RequestBody] instead if you want Content-Type auto-detection.
 *
 * Examples:
 * ```
 *   public function create(#[RequestJson] CreateOrderDto $dto): ResponseEntity { ... }
 *   public function bulk(#[Valid] #[RequestJson] OrderDto ...$items): ResponseEntity { ... }
 *   public function data(#[RequestJson] array $raw): ResponseEntity { ... }
 *   public function rename(#[RequestJson(field: 'name'), Size(5, 40)] string $name): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestJson
{
    /**
     * @param string|null $field Extract a single value from the JSON body by key
     *                           instead of hydrating the whole payload. Supports
     *                           dot-notation for nested access (e.g. 'filter.minPrice').
     *                           Omit to bind the entire body.
     */
    public function __construct(public ?string $field = null)
    {
    }
}
