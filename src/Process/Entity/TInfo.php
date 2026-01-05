<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Entity;

final class TInfo
{
    public function __construct(
        public TStatus $status,
        public ?TStats $stats = null
    ) {
    }
}
