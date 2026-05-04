<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Forces the request body to be parsed as XML, regardless of Content-Type.
 * Uses simplexml_load_string(). Invalid or empty XML produces an empty array / stdClass.
 * XML attributes and namespaces are flattened to array/object keys.
 *
 * Supported types:
 *   - array        — simplexml → json → array
 *   - stdClass     — simplexml → json → stdClass
 *   - SomeDto      — hydrated from decoded array via constructor (reflection)
 *   - SomeDto ...$v — variadic: non-list XML → wrapped as single-element array;
 *                   each element → one DTO instance
 *
 * Nested class-typed constructor parameters are hydrated recursively.
 * Error keys use dot-notation for nested paths: "coords.lat".
 * Add #[Valid] to trigger #[Constraint] validation on DTO fields after hydration.
 *
 * Examples:
 * ```
 *   public function ingest(#[RequestXml] EventDto $event): ResponseEntity { ... }
 *   public function bulk(#[Valid] #[RequestXml] ItemDto ...$items): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestXml
{
}
