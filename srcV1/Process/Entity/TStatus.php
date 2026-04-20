<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Entity;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
final class TStatus
{
    public function __construct(
        public int $pid,
        public TCondition $condition,
        public int $startedAt,
        public array $info = []
    ) {
    }

    /**
     * @return string
     */
    public function getStartedAt(): string
    {
        return date('Y-m-d H:i:s P', $this->startedAt);
    }
}
