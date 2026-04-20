<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Binds a controller method parameter to an HTTP request header.
 *
 * Header names are matched case-insensitively.
 * If $name is omitted the PHP parameter name is used (underscores → hyphens).
 *
 * Example:
 *   public function secure(#[RequestHeader('authorization')] string $token): ResponseEntity { ... }
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestHeader
{
    public function __construct(public ?string $name = null)
    {
    }
}
