<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Binds the raw request body to a controller method parameter.
 * The format is auto-detected from the Content-Type header.
 *
 * Supported types:
 *   - string       — raw body bytes, Content-Type ignored
 *   - array        — parsed body: JSON → array | XML → array
 *   - stdClass     — parsed body: JSON → stdClass | XML → stdClass
 *   - SomeDto      — hydrated from parsed body via constructor (reflection)
 *   - SomeDto ...$v — variadic: JSON array expected, each element → one DTO instance
 *
 * Content-Type dispatch:
 *   - application/json                          → json_decode($raw, true)
 *   - application/xml | text/xml               → simplexml_load_string($raw)
 *   - multipart/form-data | x-www-form-urlencoded → getParsedBody()
 *   - (anything else)                           → json_decode($raw, true)
 *
 * Add #[Valid] to trigger #[Constraint] validation on DTO fields after hydration.
 *
 * Single-field mode — pass `field` to extract one value (scalar/array/object/DTO) from
 * the parsed body instead of binding the whole payload. Content-Type detection still
 * applies to decode the body first. The value is cast to the parameter type, required by
 * default, and #[Constraint] attributes fire automatically. The field path supports
 * dot-notation for nested access ('filter.minPrice').
 *
 * Examples:
 * ```
 *   public function create(#[RequestBody] CreateOrderDto $dto): ResponseEntity { ... }
 *   public function bulk(#[Valid] #[RequestBody] OrderDto ...$items): ResponseEntity { ... }
 *   public function webhook(#[RequestBody] string $raw): ResponseEntity { ... }
 *   public function rename(#[RequestBody(field: 'name'), Size(5, 40)] string $name): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestBody
{
    /**
     * @param string|null $field Extract a single value from the parsed body by key
     *                           instead of binding the whole payload. Supports
     *                           dot-notation for nested access. Omit to bind it all.
     */
    public function __construct(public ?string $field = null)
    {
    }
}
