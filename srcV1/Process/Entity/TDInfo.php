<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Entity;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
final class TDInfo
{
    public function __construct(
        public TDStatus $status,
        public ?TStats $stats = null
    ) {
    }
}
