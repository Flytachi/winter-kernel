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

    public function __construct()
    {
        $this->pid = getmypid();
        $this->logger = LoggerRegistry::instance("[{$this->pid}] " . static::class);
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

    abstract protected function resolutionStart(): mixed;
    abstract protected function resolutionEnd(): void;
}