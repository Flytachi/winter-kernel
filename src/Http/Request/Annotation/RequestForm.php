<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

use Attribute;

/**
 * Forces the request body to be parsed as form data, regardless of Content-Type.
 * Internally uses getParsedBody() + getQueryParams() (body takes precedence).
 *
 * Supported types:
 *   - array    — merged form + query params as raw array
 *   - stdClass — merged params as stdClass
 *   - SomeDto  — hydrated from merged params via constructor (reflection)
 *              Nested objects supported via PHP bracket notation: address[city]=...
 *
 * Add #[Valid] to trigger #[Constraint] validation on DTO fields after hydration.
 *
 * Single-field mode — pass `field` to extract one value from the merged form/query data
 * instead of binding it all. The value is cast to the parameter type, required by default,
 * and #[Constraint] attributes fire automatically. Nested values addressed via dot-notation
 * map onto PHP bracket notation ('address.city' ← address[city]=...).
 *
 * Examples:
 * ```
 *   public function submit(#[RequestForm] ContactFormDto $form): ResponseEntity { ... }
 *   public function search(#[RequestForm] array $params): ResponseEntity { ... }
 *   public function rename(#[RequestForm(field: 'name'), Size(5, 40)] string $name): ResponseEntity { ... }
 * ```
 *
 * @link https://winterframe.net/docs/requests Binding a form body
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequestForm
{
    /**
     * @param string|null $field Extract a single value from the merged form/query data
     *                           by key instead of binding it all. Supports dot-notation
     *                           for nested (bracket-notation) values. Omit to bind it all.
     */
    public function __construct(public ?string $field = null)
    {
    }
}
