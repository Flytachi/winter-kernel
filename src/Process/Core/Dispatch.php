<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Core;

use Flytachi\Winter\Base\Log\LoggerRegistry;
use Flytachi\Winter\Base\Runtime;
use Flytachi\Winter\Thread\Thread;
use Psr\Log\LoggerInterface;

abstract class Dispatch implements Dispatchable
{
    protected string $exNamespace = 'dispatch';
    protected ?string $exName = null;
    protected string $exTag = 'runnable';
    protected LoggerInterface $logger;
    protected int $pid;

    final public function __construct()
    {
    }

    public static function dispatch(mixed $data = null): int
    {
        $runnable = new static();
        $thread = new Thread(
            $runnable,
            $runnable->exNamespace,
            $runnable->exName,
            $runnable->exTag
        );
        $arguments = [];
        if (!empty($data)) {
            $storeKey = uniqid('cache-');
            DispatchStore::push($storeKey, $data);
            $arguments['storeKey'] = $storeKey;
        }

        if (Runtime::isSwooleCoroutine()) {
            // In Swoole, ['file', path, 'a'] descriptors in proc_open go through
            // SWOOLE_HOOK_FILE → socket_free_defer → EBADF in posix_spawn.
            // outputTarget=null forces ['pipe','w'] for stdout/stderr — native pipes,
            // no file hook involvement.
            return $thread->start(arguments: $arguments, outputTarget: null);
        }

        return $thread->start(arguments: $arguments);
    }

    final public static function start(mixed $data = null): void
    {
        $runnable = new static();
        $arguments = [];
        if (!empty($data)) {
            $storeKey = uniqid('cache-');
            DispatchStore::push($storeKey, $data);
            $arguments['storeKey'] = $storeKey;
        }
        $runnable->run($arguments);
    }

    final public function run(array $args): void
    {
        try {
            $this->resolutionStart();
            $this->resolution(isset($args['storeKey'])
                ? DispatchStore::pop($args['storeKey'])
                : null);
        } catch (\Throwable $e) {
            $this->logger->critical(
                $e->getMessage()
                . (env('DEBUG', false)
                    ? "\n" . $e->getTraceAsString()
                    : ''
                )
            );
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
