<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Entity;

use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
final class TInfo
{
    public function __construct(
        public TStatus $status,
        public ?TStats $stats = null
    ) {
    }
}
