<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Dev\Process\Supervisor;

use Flytachi\Winter\K2\Dev\Process\Daemon;
use Flytachi\Winter\K2\Dev\Process\ProcessState;

/**
 * Keeps a {@see Daemon}'s workers alive according to its restart policy.
 *
 * The supervisor is a plain `pcntl` process with no event loop of its own: it
 * forks workers and reaps them with `pcntl_waitpid()`. Because the reactor is
 * never started here, forking is safe — each worker child then boots its own
 * Swoole coroutine runtime cleanly. This keeps one supervision path for both
 * runtimes and full control over policy, back-off and restart limits (which
 * `Swoole\Process\Pool` does not expose).
 *
 * Requires `pcntl`; a daemon cannot supervise without it.
 */
final class Supervisor
{
    /** Upper bound on exponential back-off between restarts, in seconds. */
    private const float BACKOFF_CAP = 30.0;

    private bool $stop = false;
    /** @var array<int, true> Live worker PIDs. */
    private array $workers = [];

    /**
     * Runs the supervision loop until every worker is done or a stop signal
     * arrives. Returns the terminal state.
     *
     * SIGTERM/SIGINT stop the daemon (workers are stopped, no restart). SIGHUP is
     * forwarded to the workers — a "reload" that does not stop the supervisor.
     *
     * @param Daemon $daemon Daemon supplying policy (replicas, restart, limits).
     * @param callable $worker Worker body run in each forked child.
     * @param callable $onChange Called with (int $restarts, array $workerPids) whenever the set changes.
     */
    public function run(Daemon $daemon, callable $worker, callable $onChange): ProcessState
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop = true);
        pcntl_signal(SIGINT, fn() => $this->stop = true);
        pcntl_signal(SIGHUP, fn() => $this->reloadWorkers());

        $replicas = $daemon->replicas();
        $policy = $daemon->restartPolicy();
        $maxRestarts = $daemon->maxRestarts();
        $base = max(0.0, $daemon->backoffBase());

        $this->workers = [];
        for ($i = 0; $i < $replicas; $i++) {
            $this->workers[$this->spawn($worker)] = true;
        }

        $restarts = 0;
        $failures = 0;
        $onChange($restarts, array_keys($this->workers));

        while (!$this->stop) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid <= 0) {
                usleep(100_000);
                pcntl_signal_dispatch();
                continue;
            }

            unset($this->workers[$pid]);
            $crashed = !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;

            if ($this->stop) {
                break;
            }

            if (!$policy->shouldRestart($crashed)) {
                if ($this->workers === []) {
                    return ProcessState::TERMINATED;
                }
                $onChange($restarts, array_keys($this->workers));
                continue;
            }

            $restarts++;
            $failures = $crashed ? $failures + 1 : 0;

            if ($maxRestarts > 0 && $restarts >= $maxRestarts) {
                $this->stopAll();
                return ProcessState::FAILED;
            }

            $this->interruptibleSleep($this->backoff($base, $failures));
            if ($this->stop) {
                break;
            }

            $this->workers[$this->spawn($worker)] = true;
            $onChange($restarts, array_keys($this->workers));
        }

        $this->stopAll();
        return ProcessState::TERMINATED;
    }

    /**
     * Forwards SIGHUP to every worker — a reload that leaves the supervisor
     * running. Each worker's {@see \Flytachi\Winter\K2\Dev\Process\Process::onClose()}
     * decides what reload means.
     */
    private function reloadWorkers(): void
    {
        foreach (array_keys($this->workers) as $pid) {
            posix_kill($pid, SIGHUP);
        }
    }

    /**
     * Forks a worker. In the child, inherited signal handlers are reset so the
     * worker's own runtime installs its own — then the body runs and its outcome
     * maps to the exit code.
     */
    private function spawn(callable $worker): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_DFL);
            try {
                $worker();
                exit(0);
            } catch (\Throwable) {
                // The worker logs its own failure; the non-zero code is the signal.
                exit(1);
            }
        }
        return $pid;
    }

    /**
     * Exponential back-off, capped.
     */
    private function backoff(float $base, int $failures): float
    {
        if ($base <= 0.0 || $failures <= 0) {
            return 0.0;
        }
        return min($base * (2 ** ($failures - 1)), self::BACKOFF_CAP);
    }

    /**
     * Sleeps while staying responsive to a stop signal.
     */
    private function interruptibleSleep(float $seconds): void
    {
        $remaining = $seconds;
        while ($remaining > 0 && !$this->stop) {
            usleep((int) (min($remaining, 0.1) * 1_000_000));
            pcntl_signal_dispatch();
            $remaining -= 0.1;
        }
    }

    /**
     * Signals every worker to stop and waits for them to exit.
     */
    private function stopAll(): void
    {
        foreach (array_keys($this->workers) as $pid) {
            posix_kill($pid, SIGTERM);
        }
        foreach (array_keys($this->workers) as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $this->workers = [];
    }
}
