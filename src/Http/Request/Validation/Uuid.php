<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Fails if the value is not a valid UUID (RFC 4122).
 * Optionally restricts to a specific version (1–8).
 * null is skipped — combine with #[Required] if null must be rejected.
 *
 * ```
 *   #[Uuid]    string $id  // any version — "550e8400-e29b-41d4-a716-446655440000" → pass
 *   #[Uuid(4)] string $id  // v4 only    — "550e8400-e29b-41d4-..." → pass
 *   #[Uuid]    string $id  // "not-uuid" → fail, "550e8400e29b..." → fail (no dashes)
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class Uuid implements Constraint
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const string PATTERN_VER = '/^[0-9a-f]{8}-[0-9a-f]{4}-%d[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param int|null $version Expected UUID version (1–8). null = accept any version.
     */
    public function __construct(public ?int $version = null)
    {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $str     = (string) $value;
        $pattern = $this->version !== null
            ? sprintf(self::PATTERN_VER, $this->version)
            : self::PATTERN;

        if (preg_match($pattern, $str) !== 1) {
            return $this->version !== null
                ? "must be a valid UUID v{$this->version}"
                : 'must be a valid UUID';
        }
        return null;
    }
}
