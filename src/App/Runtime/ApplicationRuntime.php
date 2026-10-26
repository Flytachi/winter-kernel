<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Runtime;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Process\Internal\LockFile;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * The running application, as the rest of the system sees it: `call run` claims it when
 * it starts, its components carry a reference to it, and anyone — the console, code in
 * another process — can ask whether it is up.
 *
 * The claim is an application lock plus a status record, the same pair a process keeps:
 * the lock says whether the master is alive (the OS releases it with the master, however
 * it died), the record says who it is and what it runs. One application runs once per
 * storage: a second `call run` is refused while the first holds the lock.
 *
 * A record from another host — a container's record read from the host through a mounted
 * storage — cannot be checked from here: its PIDs belong to another process namespace.
 * {@see isAlive()} answers null for it, and nobody may signal by its PIDs.
 *
 * @internal The application's own machinery; components and the console read it.
 */
final class ApplicationRuntime
{
    private static ?LockFile $lock = null;
    private static ?ApplicationRecord $current = null;
    private static bool $managed = false;

    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Claims the application for this master: takes its lock and writes its record.
     *
     * @param class-string<\Flytachi\Winter\Kernel\WinterApplication> $appClass
     * @param list<Component> $components What this run starts.
     * @throws ApplicationConfigException The application is already running.
     */
    public static function claim(string $appClass, array $components): ApplicationRecord
    {
        // The record store creates the runnable directory: the lock file goes in it, and a
        // lock that cannot be created is no lock at all.
        $previous = self::record($appClass);
        $lock = LockFile::take(self::lockPath($appClass));
        // A previous master that held no working lock is not excluded by ours: a free lock
        // says nothing about it, its PID does.
        $unprovable = $previous !== null && (!$previous->locked || !$lock?->reliable);
        if ($lock === null || ($unprovable && self::runsElsewhere($previous))) {
            $lock?->release();
            $running = $previous !== null ? " [PID {$previous->pid}] (since {$previous->getStartedAt()})" : '';
            throw new ApplicationConfigException(
                "{$appClass} is already running{$running}. Stop it before starting it again — "
                . 'one application runs once per storage.'
            );
        }

        $record = new ApplicationRecord(
            pid: getmypid(),
            appClass: $appClass,
            startedAt: time(),
            host: self::host(),
            locked: $lock->reliable,
            components: array_map(static fn(Component $c): array => [
                'kind'  => $c->kind->name,
                'class' => $c->class,
                'port'  => $c->kind === ComponentKind::Http || $c->kind === ComponentKind::WebSocket ? $c->port : null,
            ], $components),
        );
        try {
            self::store()->write(self::key($appClass), $record);
        } catch (\Throwable) {
            // The lock still guards the run; only the console's view is missing.
        }

        self::$lock = $lock;
        self::$current = $record;

        // A master that ends without reaching its own cleanup still removes the record.
        // Guarded by PID: every process forked from the master inherits this function.
        $pid = $record->pid;
        register_shutdown_function(static function () use ($pid, $appClass): void {
            if (getmypid() === $pid) {
                self::release($appClass);
            }
        });

        return $record;
    }

    /**
     * Removes the record and releases the lock — the master's last act. A no-op in any
     * process but the master that claimed.
     *
     * @param class-string<\Flytachi\Winter\Kernel\WinterApplication> $appClass
     */
    public static function release(string $appClass): void
    {
        if (self::$current === null || self::$current->pid !== getmypid()) {
            return;
        }
        try {
            self::store()->del(self::key($appClass));
        } catch (\Throwable) {
            // Left behind, it reads as a dead run: the lock is free.
        }
        self::$lock?->release();
        self::$lock = null;
        self::$current = null;
    }

    /**
     * The run this process belongs to: set in the master by {@see claim()} and inherited
     * by every process forked from it. Null outside `call run`.
     */
    public static function current(): ?ApplicationRecord
    {
        return self::$current;
    }

    /**
     * Marks this process as a component the application started — called by the master's
     * launcher right before the component starts, so its record names the application.
     * A process merely forked or dispatched from inside the application is not one.
     */
    public static function markManaged(): void
    {
        self::$managed = true;
    }

    /** The reference this process's record carries, or null when the application did not start it. */
    public static function managedBy(): ?ManagedBy
    {
        return self::$managed ? self::$current?->managedBy() : null;
    }

    /** Whether this process is a component the application started. */
    public static function isComponent(): bool
    {
        return self::$managed && self::$current !== null;
    }

    /**
     * The running application that has this class among its components — then starting,
     * stopping and restarting it is the application's business. Null when the
     * application is not running, or does not run this class.
     *
     * The application is this process's own: the one the kernel was booted for. A process
     * of another project sharing the storage would be another class.
     *
     * @param class-string $componentClass
     */
    public static function managing(string $componentClass): ?ApplicationRecord
    {
        $appClass = WinterApplication::getAppClass();
        if ($appClass === '') {
            return null;
        }
        $app = self::live($appClass);
        if ($app === null) {
            return null;
        }
        foreach ($app->components as $component) {
            if ($component['class'] === $componentClass) {
                return $app;
            }
        }
        return null;
    }

    /**
     * The record of the application, whether or not it is alive.
     *
     * @param class-string<\Flytachi\Winter\Kernel\WinterApplication> $appClass
     */
    public static function record(string $appClass): ?ApplicationRecord
    {
        try {
            $record = self::store()->read(self::key($appClass));
        } catch (\Throwable) {
            return null;
        }
        return $record instanceof ApplicationRecord ? $record : null;
    }

    /**
     * The application's record when its master is alive on this host; null otherwise.
     *
     * @param class-string<\Flytachi\Winter\Kernel\WinterApplication> $appClass
     */
    public static function live(string $appClass): ?ApplicationRecord
    {
        $record = self::record($appClass);
        return $record !== null && self::isAlive($record) === true ? $record : null;
    }

    /**
     * Whether the master of a record is alive.
     *
     * @return bool|null Null when the record was written on another host: its PID means
     *                   nothing here, so it can be neither confirmed nor denied.
     */
    public static function isAlive(ApplicationRecord $record): ?bool
    {
        if ($record->host !== self::host()) {
            return null;
        }
        $held = $record->locked ? LockFile::probe(self::lockPath($record->appClass)) : null;
        if ($held !== null) {
            return $held;
        }
        return function_exists('posix_getpgid') && posix_getpgid($record->pid) !== false;
    }

    /**
     * This machine as records name it — in a container, its hostname, which Docker sets
     * to the container ID. Two processes with the same host share a PID namespace.
     */
    public static function host(): string
    {
        $host = gethostname();
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * Where `flock` grants every lock, a free lock proves nothing: a previous master whose
     * PID is alive on this host, and is not this process, is taken for a running one.
     */
    private static function runsElsewhere(ApplicationRecord $previous): bool
    {
        return $previous->host === self::host()
            && $previous->pid !== getmypid()
            && function_exists('posix_getpgid')
            && posix_getpgid($previous->pid) !== false;
    }

    private static function store(): FileStorage
    {
        return Kernel::runnable('app');
    }

    private static function key(string $appClass): string
    {
        return str_replace('\\', '.', $appClass);
    }

    private static function lockPath(string $appClass): string
    {
        return Kernel::$pathStorageRunnable . '/app.' . self::key($appClass) . '.lock';
    }
}
