<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Attribute;
use Throwable;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class AdviceException
{
    /**
     * @var class-string<Throwable>[] $exceptionClassNames
     */
    public array $exceptionClassNames;

    /**
     * @param class-string<Throwable>[] $exceptionClassNames
     */
    public function __construct(string ...$exceptionClassNames)
    {
        $this->exceptionClassNames = $exceptionClassNames;
    }
}
