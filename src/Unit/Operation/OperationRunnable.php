<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

use Flytachi\Winter\Base\Algorithm;
use Flytachi\Winter\Thread\Runnable;

final class OperationRunnable implements Runnable
{
    private string $id;
    private string $name;
    private \Closure $callback;

    public function __construct(callable $callback)
    {
        $this->id = 'op_' . Algorithm::random(16);
        $this->callback = \Closure::fromCallable($callback);
        $this->name = $this->callableName();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function run(array $args): void
    {
        try {
            $result = ($this->callback)();
        } catch (\Throwable $throwable) {
        } finally {
            $pending = Operation::store()->read($this->id);
            if ($pending === 'pending') {
                Operation::store()->write($this->id, new OpResult(
                    $result ?? null,
                    $throwable ?? null
                ));
            }
        }
    }

    private function callableName(): string
    {
        $reflection = new \ReflectionFunction($this->callback);

        if ($reflection->isClosure()) {
            $class = $reflection->getClosureScopeClass();
            if ($class) {
                return '[closure] in ' . $class->getName();
            }
            return '[closure]';
        }

        $name = $reflection->getName();
        if (str_contains($name, '{closure}')) {
            return '[closure]';
        }

        return '[function] ' . $name;
    }
}
