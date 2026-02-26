<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process;

use Flytachi\FileStore\FileStorageException;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Process\Core\Dispatch;
use Flytachi\Winter\Kernel\Process\Entity\TCondition;
use Flytachi\Winter\Kernel\Process\Entity\TDInfo;
use Flytachi\Winter\Kernel\Process\Entity\TDStatus;
use Flytachi\Winter\Kernel\Process\Entity\TStats;
use Flytachi\Winter\Kernel\Process\Traits\ThreadDaemonFork;
use Flytachi\Winter\Kernel\Process\Traits\ThreadDaemonHandler;
use Flytachi\Winter\Kernel\Process\Traits\ThreadDaemonStatement;
use Flytachi\Winter\Kernel\Process\Traits\ThreadSignalHandler;
use Flytachi\Winter\Thread\Signal;

abstract class ThreadDaemon extends Dispatch
{
    use ThreadDaemonFork;
    use ThreadDaemonHandler;
    use ThreadSignalHandler;
    use ThreadDaemonStatement;

    protected static string $EC_MAIN = 'daemons';
    protected static string $EC_THREADS = 'daemons-threads';
    protected string $exNamespace = 'daemon';
    protected int $streamRps = 0;

    final public static function stmName(): string
    {
        return hash('xxh64', static::class);
    }

    final protected function resolutionStart(): void
    {
        parent::resolutionStart();
        $this->prepareSignalHandler();
        Kernel::store(static::$EC_MAIN)->write(static::stmName(), new TDStatus(
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
        Kernel::store(static::$EC_MAIN)->del(static::stmName());
    }

    /**
     * @throws DaemonException
     */
    final public static function dispatch(mixed $data = null): int
    {
        $info = static::status();
        if ($info) {
            throw new DaemonException(
                "Cluster process already exist [PID:{$info->status->pid}] ({$info->status->getStartedAt()})",
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
            $key = static::stmName();
            /** @var ?TDStatus $status */
            $status = Kernel::store(static::$EC_MAIN)->read($key);
            if (!$status) {
                return null;
            }

            if (!posix_getpgid($status->pid)) {
                Kernel::store(static::$EC_MAIN)->del($key);
                return null;
            }

            return new TDInfo(
                status: $status,
                stats: $showStats ? TStats::ofPid($status->pid) : null
            );
        } catch (FileStorageException $e) {
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
            throw new DaemonException('Cluster process has not started', HttpCode::LOCKED->value);
        }
    }
}
