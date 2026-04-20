<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Traits\ThreadProcessHandler;
use Flytachi\Winter\Kernel\Process\Traits\ThreadSignalHandler;
use Flytachi\Winter\Kernel\Process\Traits\ThreadFork;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
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
