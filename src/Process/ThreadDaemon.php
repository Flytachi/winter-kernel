<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process;

use Flytachi\FileStore\FileStorageException;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Process\Core\DaemonStore;
use Flytachi\Winter\K2\Process\Core\Dispatch;
use Flytachi\Winter\K2\Process\Entity\TCondition;
use Flytachi\Winter\K2\Process\Entity\TDInfo;
use Flytachi\Winter\K2\Process\Entity\TDStatus;
use Flytachi\Winter\K2\Process\Entity\TStats;
use Flytachi\Winter\K2\Process\Traits\ThreadDaemonFork;
use Flytachi\Winter\K2\Process\Traits\ThreadDaemonHandler;
use Flytachi\Winter\K2\Process\Traits\ThreadDaemonStatement;
use Flytachi\Winter\K2\Process\Traits\ThreadSignalHandler;
use Flytachi\Winter\Thread\Signal;

abstract class ThreadDaemon extends Dispatch
{
    use ThreadDaemonFork;
    use ThreadDaemonHandler;
    use ThreadSignalHandler;
    use ThreadDaemonStatement;

    protected static DaemonStore $STORE;
    protected string $exNamespace = 'daemon';
    protected int $streamRps = 0;

    final public static function hashName(): string
    {
        return hash('xxh64', static::class);
    }

    final protected static function store(): DaemonStore
    {
        if (!isset(static::$STORE)) {
            static::$STORE = new DaemonStore(static::class);
        }
        return static::$STORE;
    }

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
        self::store()->main()->write(static::hashName(), new TDStatus(
            pid: $this->pid,
            className: static::class,
            condition: TCondition::STARTED,
            startedAt: time(),
            streamRps: $this->streamRps,
            info: []
        ));
    }

    final protected function resolutionEnd(): void
    {
        self::store()->main()->del(static::hashName());
    }

    /**
     * @throws DaemonException
     */
    final public static function dispatch(mixed $data = null): int
    {
        $info = static::status();
        if ($info) {
            throw new DaemonException(
                "Daemon already exist [PID:{$info->status->pid}] ({$info->status->getStartedAt()})",
                HttpCode::LOCKED->value
            );
        } else {
            return parent::dispatch($data);
        }
    }

    final protected function streaming(callable $complianceCallable, ?callable $negationCallable = null): void
    {
        while (true) {
            if (static::forkQty() < $this->streamRps) {
                $complianceCallable();
            } else {
                if ($negationCallable !== null) {
                    $negationCallable();
                }
            }
            usleep((int) ($this->streamRps < 1000 ? ceil(1_000_000 / $this->streamRps) : 1000));
            pcntl_signal_dispatch();
        }
    }

    final public static function status(bool $showStats = false): ?TDInfo
    {
        try {
            $key = static::hashName();
            /** @var ?TDStatus $status */
            $status = self::store()->main()->read($key);
            if (!$status) {
                return null;
            }

            if (!posix_getpgid($status->pid)) {
                self::store()->main()->del($key);
                return null;
            }

            return new TDInfo(
                status: $status,
                stats: $showStats ? TStats::ofPid($status->pid) : null
            );
        } catch (FileStorageException) {
            return null;
        }
    }

    /**
     * @throws DaemonException
     */
    final public static function stop(): bool
    {
        $info = static::status();
        if ($info) {
            return Signal::interrupt($info->status->pid);
        } else {
            throw new DaemonException('Daemon has not started', HttpCode::LOCKED->value);
        }
    }
}
