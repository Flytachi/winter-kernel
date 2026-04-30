<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Response\Collector;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\K2\Http\Response\AdviceException;
use Flytachi\Winter\K2\Http\Response\ResponseExceptionInterface;
use ReflectionClass;

final class ExceptionCollector implements CollectorInterface
{
    /** @var list<array{className: string, exceptions: string[]}> */
    private array $specific = [];

    /** @var list<array{className: string, exceptions: string[]}> */
    private array $catchAll = [];

    public function collect(string $class, ReflectionClass $ref): void
    {
        if (!$ref->implementsInterface(ResponseExceptionInterface::class)) {
            return;
        }

        $attrs = $ref->getAttributes(AdviceException::class);
        if (empty($attrs)) {
            return;
        }

        /** @var AdviceException $advice */
        $advice = $attrs[0]->newInstance();
        $entry  = ['className' => $class, 'exceptions' => $advice->exceptionClassNames];

        if (empty($advice->exceptionClassNames)) {
            $this->catchAll[] = $entry;
        } else {
            $this->specific[] = $entry;
        }
    }

    /**
     * Returns collected handlers: specific (by exception class) first, catch-all last.
     *
     * @return list<array{className: string, exceptions: string[]}>
     */
    public function getHandlers(): array
    {
        return array_merge($this->specific, $this->catchAll);
    }
}
