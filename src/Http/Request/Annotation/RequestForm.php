<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Deserializes multipart/form-data or application/x-www-form-urlencoded body
 * into a RequestObject subclass.
 *
 * Analogue of Spring's @ModelAttribute (for form data).
 *
 * Example:
 *   public function store(#[RequestForm] UserCreateRequest $body): ResponseEntity { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestForm {}
