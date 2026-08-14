<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Annotation;

use Attribute;

/**
 * Binds a controller method parameter to a URI path variable.
 *
 * Example:
 * ```
 *   #[GetMapping('users/{id:\d+}')]
 *   public function show(#[PathVariable] int $id): ResponseEntity { ... }
 * ```
 *
 * If $name is omitted the PHP parameter name is used.
 *
 * @link https://winterframe.net/docs/requests Binding a URI segment
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PathVariable
{
    /**
     * @param string|null $name Path segment name as declared in the route pattern (e.g. {id:\d+} → 'id').
     *                          Omit to use the PHP parameter name as the lookup key.
     *                          Use an explicit name when the PHP parameter name differs from the pattern segment.
     */
    public function __construct(public ?string $name = null)
    {
    }
}
