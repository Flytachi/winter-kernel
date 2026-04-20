<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Entity;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
final class TDStatus
{
    public function __construct(
        public int $pid,
        public string $className,
        public TCondition $condition,
        public int $startedAt,
        public ?int $streamRps = null,
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
