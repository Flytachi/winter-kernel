<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Unit\Operation;

readonly class OpResult
{
    public function __construct(
        private mixed $result,
        private ?\Throwable $throwable
    ) {
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }
}
