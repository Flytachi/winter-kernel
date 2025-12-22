<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Traits\ThreadProcessHandler;
use Flytachi\Winter\Kernel\Process\Traits\ThreadSignalHandler;
use Flytachi\Winter\Kernel\Process\Traits\ThreadFork;

abstract class ThreadProcess extends Dispatch
{
    use ThreadProcessHandler;
    use ThreadSignalHandler;
    use ThreadFork;

    protected string $exNamespace = 'process';

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
    }

    final protected function resolutionEnd(): void
    {
    }

    final public function wait(int $pid, ?callable $callableEndChild = null): void
    {
        if (
            PHP_SAPI === 'cli'
            && empty($_SERVER['REMOTE_ADDR'])
            && function_exists('pcntl_signal')
        ) {
            pcntl_waitpid($pid, $status);
            if (!is_null($callableEndChild)) {
                $callableEndChild($pid, $status);
            }
        }
    }

    /**
     * Waits for child processes to finish execution.
     *
     * @param callable|null $callableEndChild Optional. A callback function that will be called
     * with the child process ID and status after it finishes execution. Default is null.
     * @return void
     */
    final public function waitAll(?callable $callableEndChild = null): void
    {
        if (
            PHP_SAPI === 'cli'
            && empty($_SERVER['REMOTE_ADDR'])
            && function_exists('pcntl_signal')
        ) {
            foreach ($this->childrenPid as $key => $pid) {
                pcntl_waitpid($pid, $status);
                if (!is_null($callableEndChild)) {
                    $callableEndChild($pid, $status);
                }
            }
        }
    }
}
