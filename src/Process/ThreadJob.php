<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Traits\ThreadJobHandler;
use Flytachi\Winter\Kernel\Process\Traits\ThreadSignalHandler;

abstract class ThreadJob extends Dispatch
{
    use ThreadJobHandler;
    use ThreadSignalHandler;

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
    }

    final protected function resolutionEnd(): void
    {
    }
}
