<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request\Validation;

use Attribute;

/**
 * Runs a custom callable for field-level validation.
 * The callable must be a string reference (closures are not allowed in attributes).
 * Signature: function(mixed $value, string $field): ?string
 * Return null to pass, return an error message string to fail.
 * null values are passed through to the callable as-is.
 *
 * ```
 *   #[Assert('App\Rules\OrderRules::validateAmount')]
 *   int $amount
 *
 *   #[Assert('App\Rules\isValidCurrency')]
 *   string $currency
 * ```
 *
 * Callable class method example:
 * ```
 *   class OrderRules {
 *       public static function validateAmount(mixed $value, string $field): ?string {
 *           return $value > 0 && $value % 100 === 0 ? null : 'must be a positive multiple of 100';
 *       }
 *   }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
readonly class Assert implements Constraint
{
    /**
     * @param string $callable Callable string reference: 'ClassName::method' or 'functionName'.
     *                         Signature: function(mixed $value, string $field): ?string
     */
    public function __construct(public string $callable)
    {
    }

    public function validate(mixed $value, string $field): ?string
    {
        if (!is_callable($this->callable)) {
            throw new \InvalidArgumentException("Assert: '{$this->callable}' is not callable");
        }
        return ($this->callable)($value, $field);
    }
}
