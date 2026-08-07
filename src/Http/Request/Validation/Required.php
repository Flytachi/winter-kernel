<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Requires the field value to be explicitly present and not null.
 *
 * Useful for nullable parameters where you must distinguish between
 * "not sent" (absent → null by framework) and "intentionally null".
 *
 * For non-nullable types (string, int, etc.) PHP already enforces presence —
 * this attribute adds value only for nullable or defaulted parameters:
 *
 * ```
 *   #[Required] ?string $name      // must be sent; null is allowed if sent explicitly
 *   #[Required] array   $tags = [] // must be present in body; default ignored
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Required implements Constraint
{
    /**
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(public ?string $message = null)
    {
    }

    public function validate(mixed $value, string $field): ?string
    {
        return $value === null ? ($this->message ?? 'is required') : null;
    }
}
