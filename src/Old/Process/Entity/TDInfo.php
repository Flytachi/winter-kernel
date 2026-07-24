<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Entity;

final class TDInfo
{
    public function __construct(
        public TDStatus $status,
        public ?TStats $stats = null
    ) {
    }
}
