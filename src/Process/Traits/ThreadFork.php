<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Traits;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use http\Exception\RuntimeException;

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
                                "[$this->pid] Thread Logic => " . $exception->getMessage()
                                . "\n" . $exception->getTraceAsString()
                            );
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->critical(
                            "[$this->pid] Thread: " . $exception->getMessage()
                            . "\n" . $exception->getTraceAsString()
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
            $this->logger->alert($e->getMessage() . "\n" . $e->getTraceAsString());
            return 0;
        }
    }

    /**
     * Executes the thread process by forking a new process and running the proc method.
     *
     * @param mixed $data The data to be passed to the proc method. Default is null.
     * @return int The PID (Process ID) of the child process if the process was successfully forked, otherwise null.
     */
    final protected function forkProc(mixed $data = null): int
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
                            $this->forkResolution($data);
                        } catch (\Throwable $exception) {
                            $this->logger->critical(
                                "[$this->pid] Thread(proc) Logic => " . $exception->getMessage()
                                . "\n" . $exception->getTraceAsString()
                            );
                        }
                    } catch (\Throwable $exception) {
                        $this->logger->critical(
                            "[$this->pid] Thread(proc): " . $exception->getMessage()
                            . "\n" . $exception->getTraceAsString()
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
            $this->logger->alert($e->getMessage() . "\n" . $e->getTraceAsString());
            return 0;
        }
    }

    protected function forkStart(): void
    {
        $this->iAmChild = true;
        $this->logger = LoggerRegistry::instance("[{$this->pid}] " . static::class);
        if (PHP_SAPI === 'cli') {
            cli_set_process_title('extra thread ' . static::class);
        }
    }

    protected function forkEnd(): void
    {
    }

    public function forkResolution(mixed $data = null): void
    {
        $this->logger->info("-fork- running");
    }
}
