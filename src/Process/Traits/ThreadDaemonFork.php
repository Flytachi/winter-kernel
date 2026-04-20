<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Traits;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use RuntimeException;

trait ThreadDaemonFork
{
    /** @var bool $childrenPidSave Children process ids on/off */
    protected bool $childrenPidSave = true;
    /** @var array<int> $childrenPids Children process ids */
    protected array $childrenPids = [];
    private bool $iAmChild = false;

    final protected function fork(callable $function): int
    {
        try {
            $pid = pcntl_fork();
            if ($pid != -1) {
                if ($pid == 0) {
                    // Child process
                    try {
                        $this->pid = getmypid();
                        $this->forkStart('fork');
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
                        $this->childrenPids[] = $pid;
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
                        $this->childrenPids[] = $pid;
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
        $this->preparationForkBefore($this->pid);
    }

    protected function forkEnd(): void
    {
        $this->preparationForkAfter($this->pid);
    }

    public function anonymousResolution(mixed $data = null): void
    {
        $this->logger->info("-forkAnonymous- running");
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
            pcntl_signal_dispatch();
        }
    }

    final public function waitAll(?callable $callableEndChild = null): void
    {
        if (
            PHP_SAPI === 'cli'
            && empty($_SERVER['REMOTE_ADDR'])
            && function_exists('pcntl_signal')
        ) {
            foreach (static::forkList() as $pid) {
                pcntl_waitpid($pid, $status);
                if (!is_null($callableEndChild)) {
                    $callableEndChild($pid, $status);
                }
                pcntl_signal_dispatch();
            }
        }
    }
}
