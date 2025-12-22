<?php

namespace Flytachi\Winter\Kernel\Process\Core;

use Flytachi\Winter\Base\Interface\Stereotype;
use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Thread\Thread;
use Psr\Log\LoggerInterface;

abstract class Dispatch implements Dispatchable
{
    protected LoggerInterface $logger;
    protected int $pid;

    final public function __construct()
    {
    }

    public static function dispatch(mixed $data = null): int
    {
        $thread = new Thread(
            new static(),
            'job',
        );
        return $thread->start();
    }

    public static function start(mixed $data = null): void
    {
        $runnable = new static();
        $runnable->run();
    }

    final public function run(): void
    {
        try {
            $data = $this->resolutionStart();
            $this->resolution($data);
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage());
        } finally {
            $this->resolutionEnd();
        }
    }

    protected function resolutionStart(): mixed
    {
        $this->pid = getmypid();
        $this->logger = LoggerRegistry::instance("[{$this->pid}] " . static::class);
        return null;
    }

    abstract protected function resolutionEnd(): void;
}