<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

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
 * Single-field mode — pass `field` to extract one value (scalar/array/object/DTO) from
 * the parsed XML instead of binding the whole document. The value is cast to the parameter
 * type, required by default, and #[Constraint] attributes fire automatically. The field
 * path supports dot-notation for nested elements ('coords.lat').
 *
 * Examples:
 * ```
 *   public function ingest(#[RequestXml] EventDto $event): ResponseEntity { ... }
 *   public function bulk(#[Valid] #[RequestXml] ItemDto ...$items): ResponseEntity { ... }
 *   public function lat(#[RequestXml(field: 'coords.lat')] float $lat): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestXml
{
    /**
     * @param string|null $field Extract a single value from the parsed XML by key
     *                           instead of binding the whole document. Supports
     *                           dot-notation for nested access. Omit to bind it all.
     */
    public function __construct(public ?string $field = null)
    {
    }
}
