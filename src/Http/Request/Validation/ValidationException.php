<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Request\Validation;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Response\ResponseException;

/**
 * Thrown when #[Valid] constraint validation fails on a DTO parameter.
 * Produces a 422 Unprocessable Entity response with a structured errors map.
 *
 * Response body:
 * ```
 *   {
 *     "code": 422,
 *     "message": "Validation failed",
 *     "errors": {
 *       "title":  ["is required"],
 *       "amount": ["must be at least 1"]
 *     }
 *   }
 * ```
 */
class ValidationException extends ResponseException
{
    /**
     * @param array<string, string[]> $errors Field name → list of error messages.
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed', HttpCode::UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
