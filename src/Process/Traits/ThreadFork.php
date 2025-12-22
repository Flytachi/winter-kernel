<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Traits;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use RuntimeException;

trait ThreadFork
{
    /** @var bool $childrenPidSave Children process ids on/off */
    protected bool $childrenPidSave = true;
    /** @var array<int> $childrenPid Children process ids */
    protected array $childrenPid = [];
    private bool $iAmChild = false;

    /**
     * Executes a function in a separate child process using forking.
     *
     * @param callable $function The function to be executed in the child process.
     * @return int The process ID of the child process.
     */
    final protected function fork(callable $function): int
    {
        try {
            $pid = pcntl_fork();
            if ($pid != -1) {
                if ($pid == 0) {
                    // Child process
                    try {
                        $this->pid = getmypid();
                        $this->forkStart();
                        try {
                            $function();
                        } catch (\Throwable $exception) {
                            $this->logger->critical(
                                'Process fork logic => ' . $exception->getMessage()
                                . (env('DEBUG', false)
                                    ? "\n" . $exception->getTraceAsString()
                                    : ''
                                )
                            );
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->critical(
                            'Process fork => ' . $exception->getMessage()
                            . (env('DEBUG', false)
                                ? "\n" . $exception->getTraceAsString()
                                : ''
                            )
                        );
                    } finally {
                        $this->forkEnd();
                        exit(0);
                    }
                } else {
                    // Parent process
                    if ($this->childrenPidSave) {
                        $this->childrenPid[] = $pid;
                    }
                    return $pid;
                }
            } else {
                throw new RuntimeException("Unable to fork process.");
            }
        } catch (\Throwable $e) {
            $this->logger->alert(
                $e->getMessage()
                . (env('DEBUG', false)
                    ? "\n" . $e->getTraceAsString()
                    : ''
                )
            );
            return 0;
        }
    }

    /**
     * Executes the thread process by forking a new process and running the proc method.
     *
     * @param mixed $data The data to be passed to the proc method. Default is null.
     * @return int The PID (Process ID) of the child process if the process was successfully forked, otherwise null.
     */
    final protected function forkAnonymous(mixed $data = null): int
    {
        try {
            $pid = pcntl_fork();
            if ($pid != -1) {
                if ($pid == 0) {
                    // Child process
                    try {
                        $this->pid = getmypid();
                        $this->forkStart('anonymous');
                        try {
                            $this->anonymousResolution($data);
                        } catch (\Throwable $exception) {
                            $this->logger->critical(
                                'Process fork logic (anonymous) => ' . $exception->getMessage()
                                . (env('DEBUG', false)
                                    ? "\n" . $exception->getTraceAsString()
                                    : ''
                                )
                            );
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->critical(
                            'Process fork (anonymous) => ' . $exception->getMessage()
                            . (env('DEBUG', false)
                                ? "\n" . $exception->getTraceAsString()
                                : ''
                            )
                        );
                    } finally {
                        $this->forkEnd();
                        exit(0);
                    }
                } else {
                    // Parent process
                    if ($this->childrenPidSave) {
                        $this->childrenPid[] = $pid;
                    }
                    return $pid;
                }
            } else {
                throw new RuntimeException("Unable to fork process.");
            }
        } catch (\Throwable $e) {
            $this->logger->alert(
                $e->getMessage()
                . (env('DEBUG', false)
                    ? "\n" . $e->getTraceAsString()
                    : ''
                )
            );
            return 0;
        }
    }

    protected function forkStart(string $tag): void
    {
        $this->iAmChild = true;
        $this->logger = LoggerRegistry::instance("[{$this->pid}] " . static::class);
        if (
            PHP_SAPI === 'cli'
            && empty($_SERVER['REMOTE_ADDR'])
            && function_exists('pcntl_signal')
        ) {
            $parentTitle = cli_get_process_title();
            $title = str_replace(
                $this->exNamespace,
                ($this->exNamespace . '(fork)'),
                $parentTitle
            );
            $title = str_replace($this->exTag, $tag, $title);
            cli_set_process_title($title);
        }
    }

    protected function forkEnd(): void
    {
    }

    public function anonymousResolution(mixed $data = null): void
    {
        $this->logger->info("-forkAnonymous- running");
    }
}
