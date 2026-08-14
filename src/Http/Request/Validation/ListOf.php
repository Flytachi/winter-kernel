<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Declares that an array parameter holds a collection of a specific DTO class.
 * Each element is hydrated via the same reflection-based mechanism as plain DTOs.
 * Constraint attributes on the element DTO constructor params are validated inline.
 *
 * Error keys use bracket-dot notation: "items[0].name", "items[1].quantity".
 *
 * ```
 * class OrderDto
 * {
 *     public function __construct(
 *         #[ListOf(ItemDto::class)]
 *         public array $items = [],
 *     ) {}
 * }
 * ```
 *
 * @link https://winterframe.net/docs/validation Validating a collection of objects
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /** @param string $class Fully-qualified class name of the element DTO. */
    public function __construct(public string $class)
    {
    }
}
