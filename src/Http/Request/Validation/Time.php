<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid time string matching the given format.
 * Default format accepts both H:i and H:i:s.
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Time]          string $time  // "14:30" → pass, "14:30:00" → pass
 *   #[Time('H:i')]   string $time  // "14:30" → pass, "14:30:00" → fail
 *   #[Time('H:i:s')] string $time  // "14:30:00" → pass, "14:30" → fail
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Time implements Constraint
{
    /**
     * @param string|null $format PHP time format string. null = accept 'H:i' or 'H:i:s'.
     * @param string|null $message Custom error message that overrides the default one.
     */
    public function __construct(
        public ?string $format = null,
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
        $str = (string) $value;

        if ($this->format !== null) {
            return $this->matchesFormat($str, $this->format)
                ? null
                : ($this->message ?? "must be a valid time ({$this->format})");
        }

        // default: accept H:i or H:i:s
        if ($this->matchesFormat($str, 'H:i') || $this->matchesFormat($str, 'H:i:s')) {
            return null;
        }
        return $this->message ?? 'must be a valid time (H:i or H:i:s)';
    }

    private function matchesFormat(string $value, string $format): bool
    {
        $dt     = \DateTime::createFromFormat('!' . $format, $value);
        $errors = \DateTime::getLastErrors();
        return $dt !== false && (!$errors || ($errors['error_count'] === 0 && $errors['warning_count'] === 0));
    }
}
