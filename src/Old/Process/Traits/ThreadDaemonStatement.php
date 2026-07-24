<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Old\Process\Traits;

use Flytachi\FileStore\FileStorageException;
use Flytachi\Winter\K2\Old\Process\Entity\TCondition;
use Flytachi\Winter\K2\Old\Process\Entity\TDStatus;
use Flytachi\Winter\K2\Old\Process\Entity\TInfo;
use Flytachi\Winter\K2\Old\Process\Entity\TStats;
use Flytachi\Winter\K2\Old\Process\Entity\TStatus;

trait ThreadDaemonStatement
{
    protected function preparation(): void
    {
    }

    /**
     * @throws FileStorageException
     */
    final public static function forkQty(): int
    {
        $keys = static::store()->threads()->keys();
        return count($keys);
    }

    /**
     * @throws FileStorageException
     */
    final public static function forkList(): array
    {
        $keys = static::store()->threads()->keys();
        foreach ($keys as $key => $path) {
            $keys[$key] = (int) trim($path, '_');
        }
        return $keys;
    }

    /**
     * @return TInfo[]
     * @throws FileStorageException
     */
    final public static function forkListInfo(bool $showStats = false): array
    {
        $store = static::store()->threads();
        $keys = $store->keys();
        foreach ($keys as $key => $path) {
            $pid = (int) trim($path, '_');
            $keys[$key] = new TInfo(
                status: $store->read($path),
                stats: $showStats ? TStats::ofPid($pid) : null
            );
        }
        return $keys;
    }

    /**
     * @throws FileStorageException
     */
    final public static function forkInfo(int $forkPid, bool $showStats = false): ?TInfo
    {
        $store = static::store()->threads();
        $status = $store->read("_{$forkPid}_");
        if (!$status) {
            return null;
        }

        return new TInfo(
            status: $status,
            stats: $showStats ? TStats::ofPid($forkPid) : null
        );
    }

    final public static function forkSetCondition(int $threadPid, TCondition $newCondition): void
    {
        $store = static::store()->threads();
        /** @var TStatus $status */
        $status = $store->read("_{$threadPid}_");
        $status->condition = $newCondition;
        $store->write("_{$threadPid}_", $status);
    }

    final protected function setCondition(TCondition $newCondition): void
    {
        $store = static::store()->main();
        $key = static::hashName();
        /** @var TDStatus $status */
        $status = $store->read($key);
        $status->condition = $newCondition;
        $store->write($key, $status);
    }

    final protected function setInfo(array $newInfo): void
    {
        $store = static::store()->main();
        $key = static::hashName();
        /** @var TDStatus $status */
        $status = $store->read($key);
        $status->info = $newInfo;
        $store->write($key, $status);
    }

    final protected function prepare(int $streamRps = 0): void
    {
        $store = static::store()->main();
        $key = static::hashName();
        /** @var TDStatus $status */
        $status = $store->read($key);
        $status->condition = TCondition::PREPARATION;
        $store->write($key, $status);

        $status->streamRps = $streamRps;
        $this->streamRps = $streamRps;
        $store->write($key, $status);
        $this->preparation();

        /** @var TDStatus $status */
        $status = $store->read($key);
        $status->condition = TCondition::ACTIVE;
        $store->write($key, $status);
    }

    protected function preparationForkBefore(int $forkPid): void
    {
        static::store()->threads()
            ->write("_{$forkPid}_", new TStatus(
                pid: $forkPid,
                condition: TCondition::STARTED,
                startedAt: time()
            ));
    }

    protected function preparationForkAfter(int $forkPid): void
    {
        static::store()->threads()->del("_{$forkPid}_");
    }
}
