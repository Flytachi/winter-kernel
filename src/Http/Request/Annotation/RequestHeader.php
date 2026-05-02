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
 * ```
 *   public function secure(#[RequestHeader('authorization')] string $token): ResponseEntity { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class RequestHeader
{
    /**
     * @param string|null $name Exact HTTP header name (e.g. 'X-Trace-Id', 'Authorization').
     *                          Omit to derive the name from the PHP parameter name automatically:
     *                          camelCase ($xRequestedWith) and snake_case ($x_requested_with)
     *                          are both converted to kebab-case (x-requested-with).
     *                          Header lookup is always case-insensitive.
     */
    public function __construct(public ?string $name = null)
    {
    }
}
