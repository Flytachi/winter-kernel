<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process;

use Flytachi\Winter\K2\Old\Process\Core\Dispatch;
use Flytachi\Winter\K2\Old\Process\Traits\ThreadFork;
use Flytachi\Winter\K2\Old\Process\Traits\ThreadProcessHandler;
use Flytachi\Winter\K2\Old\Process\Traits\ThreadSignalHandler;

abstract class ThreadProcess extends Dispatch
{
    use ThreadFork;
    use ThreadSignalHandler;
    use ThreadProcessHandler;

    protected string $exNamespace = 'process';

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
    }

    final protected function resolutionEnd(): void
    {
    }

    final public static function dispatch(mixed $data = null): int
    {
        return parent::dispatch($data);
    }
}
