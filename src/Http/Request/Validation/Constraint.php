<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

/**
 * Contract for all parameter-level validation attributes.
 *
 * Implementations are placed as PHP attributes on DTO constructor parameters.
 * The framework calls validate() for each constraint after hydrating the object,
 * when the controller parameter is annotated with #[Valid].
 *
 * Return null  → value is valid.
 * Return string → validation failed; the string is the error message.
 */
interface Constraint
{
    public function validate(mixed $value, string $field): ?string;
}
