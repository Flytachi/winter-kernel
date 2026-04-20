<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Deserializes a JSON request body into a RequestObject subclass.
 *
 * Reads raw body, expects application/json.
 * Analogue of Spring's @RequestBody.
 *
 * Example:
 *   public function store(#[RequestBody] UserCreateRequest $body): ResponseEntity { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestBody
{
}
