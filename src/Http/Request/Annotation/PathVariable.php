<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Annotation;

use Attribute;

/**
 * Binds a controller method parameter to a URI path variable.
 *
 * Example:
 *   #[GetMapping('users/{id:\d+}')]
 *   public function show(#[PathVariable] int $id): ResponseEntity { ... }
 *
 * If $name is omitted the PHP parameter name is used.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class PathVariable
{
    public function __construct(public ?string $name = null)
    {
    }
}
