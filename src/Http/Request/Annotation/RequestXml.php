<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Deserializes a XML request body into a RequestObject subclass.
 *
 * Reads raw body, expects application/xml or text/xml.
 * Analogue of Spring's @RequestBody.
 *
 * Example:
 * ```
 *   public function store(#[RequestXml] UserCreateRequest $body): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestXml
{
}
