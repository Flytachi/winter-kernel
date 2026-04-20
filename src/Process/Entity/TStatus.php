<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Entity;

final class TStatus
{
    public function __construct(
        public int $pid,
        public TCondition $condition,
        public int $startedAt,
        public array $info = []
    ) {
    }

    public function getStartedAt(): string
    {
        return date('Y-m-d H:i:s P', $this->startedAt);
    }
}
