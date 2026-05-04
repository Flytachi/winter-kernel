<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

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
 * Use #[RequestBody] instead if you want Content-Type auto-detection.
 *
 * Examples:
 * ```
 *   public function create(#[RequestJson] CreateOrderDto $dto): ResponseEntity { ... }
 *   public function bulk(#[Valid] #[RequestJson] OrderDto ...$items): ResponseEntity { ... }
 *   public function data(#[RequestJson] array $raw): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestJson
{
}
