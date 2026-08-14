<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Triggers validation of a resolved DTO parameter.
 *
 * Place alongside a body/query annotation on a controller method parameter.
 * After the framework hydrates the object, it reads all #[Constraint] attributes
 * from the DTO constructor parameters and runs them. Failed constraints throw
 * ValidationException (422 Unprocessable Entity) with a field → messages map.
 *
 * Example:
 * ```
 *   public function create(
 *       #[Valid] #[RequestBody] CreateOrderDto $dto,
 *   ): ResponseEntity
 * ```
 *
 * The DTO declares rules directly on its constructor parameters:
 * ```
 *   class CreateOrderDto {
 *       public function __construct(
 *           #[Required] #[Max(255)]
 *           public readonly string $title,
 *           #[Required] #[Min(1)]
 *           public readonly int $amount,
 *       ) {}
 *   }
 * ```
 *
 * @link https://winterframe.net/docs/validation Constraints, error shape and nested objects
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Valid
{
}
