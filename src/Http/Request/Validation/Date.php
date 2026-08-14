<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid date string matching the given format.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Date]            string $date  // "2024-01-31" → pass  (default Y-m-d)
 *   #[Date('d.m.Y')]   string $date  // "31.01.2024" → pass
 *   #[Date]            string $date  // "2024-13-01" → fail  (invalid month)
 * ```
 *
 * @link https://winterframe.net/docs/validation Constraints, error shape and messages
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Date implements Constraint
{
    /**
     * @param string $format PHP date format string. Defaults to 'Y-m-d' (e.g. "2024-01-31").
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(
        public string $format = 'Y-m-d',
        public ?string $message = null,
    ) {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return null;
        }
        $dt     = \DateTime::createFromFormat('!' . $this->format, (string) $value);
        $errors = \DateTime::getLastErrors();
        if ($dt === false || ($errors && ($errors['error_count'] > 0 || $errors['warning_count'] > 0))) {
            return $this->message ?? "must be a valid date ({$this->format})";
        }
        return null;
    }
}
