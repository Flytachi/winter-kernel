<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid datetime string.
 * Without a format: accepts any ISO 8601 string parseable by PHP.
 * With a format: strictly validates against it.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Datetime]                   string $dt  // "2024-01-31T14:30:00" → pass
 *   #[Datetime('Y-m-d H:i:s')]    string $dt  // "2024-01-31 14:30:00" → pass
 *   #[Datetime]                   string $dt  // "not-a-date" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Datetime implements Constraint
{
    /**
     * @param string|null $format PHP datetime format string. null = flexible ISO 8601 via DateTimeImmutable.
     */
    public function __construct(public ?string $format = null)
    {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return null;
        }
        $str = (string) $value;

        if ($this->format !== null) {
            $dt     = \DateTime::createFromFormat('!' . $this->format, $str);
            $errors = \DateTime::getLastErrors();
            if ($dt === false || ($errors && ($errors['error_count'] > 0 || $errors['warning_count'] > 0))) {
                return "must be a valid datetime ({$this->format})";
            }
            return null;
        }

        // flexible ISO 8601 — let PHP decide
        try {
            new \DateTimeImmutable($str);
            return null;
        } catch (\Exception) {
            return 'must be a valid datetime';
        }
    }
}
