<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Core;

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
        $thread = new Thread(new static(), 'job');
        $arguments = [];
        if ($data !== null) {
            $storeKey = uniqid('cache-');
            PStore::push($storeKey, $data);
            $arguments['storeKey'] = $storeKey;
        }
        return $thread->start(arguments: $arguments);
    }

    public static function start(mixed $data = null): void
    {
        $runnable = new static();
        $runnable->run([]);
    }

    final public function run(array $args): void
    {
        try {
            $this->resolutionStart();
            $this->logger->alert('args: ' . print_r($args, true));
            $this->resolution($args);
        } catch (\Throwable $e) {
            $this->logger->critical($e->getMessage());
        } finally {
            $this->resolutionEnd();
        }
    }

    protected function resolutionStart(): void
    {
        $this->pid = getmypid();
        $this->logger = LoggerRegistry::instance("[{$this->pid}] " . static::class);
    }

    abstract protected function resolutionEnd(): void;
}