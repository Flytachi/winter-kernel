<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

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
 * Examples:
 * ```
 *   public function submit(#[RequestForm] ContactFormDto $form): ResponseEntity { ... }
 *   public function search(#[RequestForm] array $params): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestForm
{
}
