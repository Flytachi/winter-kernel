<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Process\Internal;

/**
 * Ends the current process with a chosen status, in contexts where `exit()` cannot.
 *
 * Inside a process Swoole created — a coroutine, a `Swoole\Process`, a user process added
 * to a server with `addProcess()`, or anything forked from one — `exit()` does not end
 * anything. Swoole raises {@see \Swoole\ExitException} instead, execution continues past
 * the call, and an uncaught one produces a fatal with status 255 rather than the status
 * that was asked for. A daemon master runs exactly there, so its forked workers inherit
 * the same handicap.
 *
 * That matters because the status is the only thing a supervisor reads: `pcntl_wifexited()`
 * plus a zero status is what separates "the worker finished" from "the worker crashed".
 * Losing it turns every clean shutdown into a restart.
 *
 * So when `exit()` is neutralised the process image is replaced instead. `exec` leaves no
 * PHP — and therefore no Swoole — behind, and the shell it becomes exits with precisely
 * the status asked for. SIGKILL remains as a last resort for the case where even that is
 * unavailable: the status is lost there, but the process still dies where it was told to.
 *
 * @internal
 */
final class Termination
{
    /** Static utility — not instantiable. */
    private function __construct()
    {
    }

    /**
     * Ends this process with `$code` and does not return.
     *
     * Note that the `exec` path skips shutdown functions: the image is replaced, so
     * nothing PHP registered runs. Callers that need `onShutdown()` (or any other
     * teardown) must have run it before calling this — which the process layer does, on
     * its normal path, well before the exit.
     *
     * @param int $code Exit status to deliver.
     */
    public static function leave(int $code): void
    {
        try {
            exit($code);
        } catch (\Throwable) {
            // Only reachable when exit() was neutralised by a Swoole context.
        }

        if (function_exists('pcntl_exec')) {
            // A plain `sh -c 'exit N'` carries the status out exactly. $code is an int,
            // so there is nothing to escape.
            @pcntl_exec('/bin/sh', ['-c', 'exit ' . $code]);
            // pcntl_exec() returns only when the image could not be replaced.
        }

        if (function_exists('posix_kill')) {
            @posix_kill(getmypid(), SIGKILL);
        }

        // SIGKILL to self lands before the next statement runs. The loop is here so this
        // function can never return into a caller that assumed the process was gone.
        while (true) {
            usleep(1000);
        }
    }
}
