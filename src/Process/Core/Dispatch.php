<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Core;

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\Logger\LoggerFactory;
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
        $runnable = Container::getInstance()->make(static::class);
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

        return $thread->start(
            arguments: $arguments
        );
    }

    final public static function start(mixed $data = null): void
    {
        $runnable = Container::getInstance()->make(static::class);
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
            $this->logger->error(
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
        $this->logger = LoggerFactory::getLogger(static::class);
    }

    abstract protected function resolutionEnd(): void;
}
