<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Attribute;
use Throwable;

/**
 * Marks a ResponseExceptionInterface implementation as a global exception handler
 * (Spring's @ControllerAdvice / @ExceptionHandler pattern).
 *
 * Without arguments  → catches ALL unhandled Throwables (fallback handler).
 * With class names   → catches only the listed exception types.
 *
 * ExceptionWrapper scans the project for these at startup and routes
 * Throwables to the most specific matching handler.
 *
 * The base class to extend is {@see \Flytachi\Winter\Kernel\Http\Stereotype\ExceptionResponseBase}.
 *
 * Example — catch specific exception:
 *   #[AdviceException(NotFoundException::class)]
 *   class NotFoundResponse extends ExceptionResponseBase { ... }
 *
 * Example — catch all:
 *   #[AdviceException]
 *   class GlobalErrorResponse extends ExceptionResponseBase { ... }
 *
 * @link https://winterframe.net/docs/error-handling Turning exceptions into responses
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AdviceException
{
    /** @var class-string<Throwable>[] */
    public array $exceptionClassNames;

    /** @param class-string<Throwable> ...$exceptionClassNames */
    public function __construct(string ...$exceptionClassNames)
    {
        $this->exceptionClassNames = $exceptionClassNames;
    }
}
