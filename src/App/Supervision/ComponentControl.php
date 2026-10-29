<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\Kernel\App\Runtime\ManagedBy;
use Flytachi\Winter\Kernel\Kernel;

/**
 * Requests to the application's supervisor about a component — how the console, or
 * code in any process, starts or restarts a component while the application runs: one
 * it was declared with, or any other, which it takes in.
 *
 * A request is a small record per class; the supervisor picks it up within half a
 * second and removes it. It names the run of the application it is meant for: one
 * addressed to a previous run (the application restarted meanwhile), or older than a
 * minute, is dropped — what the operator asked of a run that is gone does not carry
 * over to the next.
 *
 * Stopping needs no request: a stop signal to the component is one, and the supervisor
 * leaves a component stopped on request down.
 *
 * @internal Between the framework's control surface and the supervisor.
 */
final class ComponentControl
{
    public const string START = 'start';
    public const string RESTART = 'restart';

    /** Seconds a request stays valid. */
    private const int TTL = 60;

    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Asks the supervisor of a run to start or restart a component — one it was declared
     * with, or any other process class, which it then takes in ({@see
     * \Flytachi\Winter\Kernel\App\Component::adopted()}).
     *
     * Each request is a record of its own: two requests for one class do not overwrite
     * each other.
     *
     * @param class-string $class
     * @param array<string, string> $args What the component is started with — a
     *     WebSocket's `port` and `host`; used when the application takes it in.
     * @param bool $reply Whether the caller waits for the answer ({@see awaitReply()}).
     * @return string The request's id — what its reply is found by.
     */
    public static function request(
        ManagedBy $run,
        string $class,
        string $action,
        array $args = [],
        bool $reply = false,
    ): string {
        $id = bin2hex(random_bytes(8));
        self::store()->write(self::key($class) . '.' . $id, [
            'class'  => $class,
            'action' => $action,
            'args'   => $args,
            'reply'  => $reply ? $id : null,
            'run'    => $run,
            'at'     => time(),
        ]);
        return $id;
    }

    /**
     * The supervisor's answer to a request that waits for one: the PID it started, or 0
     * and why not.
     *
     * @internal Called by the supervisor.
     */
    public static function reply(string $id, int $pid, string $error = ''): void
    {
        $store = self::replies();
        // An answer the caller stopped waiting for is never read, nor deleted: it expires,
        // and the next answer sweeps away the expired ones.
        try {
            $store->gc();
        } catch (\Throwable) {
            // Left for the next sweep.
        }
        $store->write($id, ['pid' => $pid, 'error' => $error, 'at' => time()], time() + self::TTL);
    }

    /**
     * Waits for the answer to a request.
     *
     * @return array{pid: int, error: string}|null Null when none came in time — the
     *     application is busy, or gone.
     */
    public static function awaitReply(string $id, float $timeout): ?array
    {
        $store = self::replies();
        $deadline = microtime(true) + $timeout;
        do {
            usleep(20_000);
            try {
                $reply = $store->read($id);
            } catch (\Throwable) {
                $reply = null;
            }
            if (is_array($reply)) {
                $store->del($id);
                return ['pid' => (int) ($reply['pid'] ?? 0), 'error' => (string) ($reply['error'] ?? '')];
            }
        } while (microtime(true) < $deadline);
        return null;
    }

    /**
     * Takes every pending request meant for this run. What is taken is removed; what is
     * meant for another run, or stale, is removed and dropped.
     *
     * @return list<ControlRequest>
     */
    public static function pending(ManagedBy $run): array
    {
        $store = self::store();
        $requests = [];
        foreach ($store->keys() as $key) {
            try {
                $request = $store->read($key);
                $store->del($key);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($request)) {
                continue;
            }
            $for = $request['run'] ?? null;
            $fresh = time() - (int) ($request['at'] ?? 0) <= self::TTL;
            $action = $request['action'] ?? null;
            $class = $request['class'] ?? null;
            if (
                !$for instanceof ManagedBy || $for != $run || !$fresh || !is_string($class)
                || ($action !== self::START && $action !== self::RESTART)
            ) {
                continue;
            }
            $args = array_filter(
                is_array($request['args'] ?? null) ? $request['args'] : [],
                static fn($value, $name): bool => is_string($name) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            );
            $reply = is_string($request['reply'] ?? null) ? $request['reply'] : null;
            $requests[] = new ControlRequest($class, $action, $args, $reply);
        }
        return $requests;
    }

    private static function store(): FileStorage
    {
        // Plain file names: the supervisor walks the requests, and a hashed name cannot be
        // read back by what keys() returns.
        return Kernel::runnable('requests', isHash: false);
    }

    private static function replies(): FileStorage
    {
        return Kernel::runnable('replies', isHash: false);
    }

    private static function key(string $class): string
    {
        return str_replace('\\', '.', $class);
    }
}
