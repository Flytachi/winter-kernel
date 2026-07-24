<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Process\Daemon;

use Flytachi\Winter\K2\Process\Activity;
use Flytachi\Winter\K2\Process\ProcessState;

/**
 * Fleet supervision for a {@see Daemon} — the master's behaviour, mixed in as
 * all-private methods so none of it leaks into the application-facing API.
 *
 * The master is a plain `pcntl` process with no event loop of its own: it forks
 * workers and reaps them with `pcntl_waitpid()`. Because the reactor is never
 * started here, forking is safe — each worker child then boots its own Swoole
 * coroutine runtime cleanly. This keeps one supervision path for both runtimes
 * and full control over policy, back-off, autoscaling and the stop sequence
 * (which `Swoole\Process\Pool` does not expose).
 *
 * It is the single authority over the fleet size. Each {@see Slot} carries its
 * {@see SlotState}, which marks intent: a worker retired on purpose (scale-down
 * or stop) is never restarted, while one that died on its own is. That is what
 * keeps the {@see RestartPolicy} and the autoscaler from fighting.
 *
 * Requires `pcntl`; a daemon cannot supervise without it.
 */
trait SupervisesFleet
{
    /** Loop granularity, in seconds. */
    private const float TICK = 0.1;
    /** How often the fleet status is re-persisted even without a fleet change, in seconds. */
    private const float STATUS_INTERVAL = 1.0;
    /** Upper bound on exponential back-off between restarts, in seconds. */
    private const float BACKOFF_CAP = 30.0;

    /** @var array<int, Slot> Slots keyed by their stable index. */
    private array $slots = [];
    private bool $stop = false;
    private int $totalRestarts = 0;
    private ProcessState $finalState = ProcessState::TERMINATED;

    /** @var list<array{0: float, 1: int}> Ring of [microtime, rawDesired] for scaling damping. */
    private array $desiredHistory = [];
    private float $lastScaleAt = 0.0;
    private float $lastReconcileAt = 0.0;
    private float $lastStatusAt = 0.0;

    // -------------------------------------------------------------------------
    // Main loop
    // -------------------------------------------------------------------------

    /**
     * Runs the supervision loop until every worker is done or a stop signal
     * arrives. Returns the terminal state.
     *
     * @param callable $onChange Persist the DaemonStatus; called whenever the fleet changes.
     */
    private function superviseFleet(callable $onChange): ProcessState
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->requestFleetStop());
        pcntl_signal(SIGINT, fn() => $this->requestFleetStop());
        pcntl_signal(SIGHUP, fn() => $this->reload());
        pcntl_signal(SIGUSR1, fn() => $this->forward(SIGUSR1));
        pcntl_signal(SIGUSR2, fn() => $this->forward(SIGUSR2));

        $replicas = $this->replicas();
        for ($i = 0; $i < $replicas; $i++) {
            $this->slots[$i] = new Slot($i);
        }

        while (true) {
            $changed = $this->refreshWorkers();
            if (!$this->stop) {
                $this->watchdog();
            }
            $this->reapAndEnforce($onChange);

            if ($this->stop) {
                if ($this->aliveCount() === 0) {
                    break;
                }
            } else {
                $now = microtime(true);
                $interval = max(0.05, $this->scalingPolicy()->scaleInterval);
                if (($now - $this->lastReconcileAt) >= $interval) {
                    $this->lastReconcileAt = $now;
                    $this->fireTick();
                    $this->reconcile($onChange);
                }
            }

            // Heartbeat the status so activity and STARTING → RUNNING promotions
            // reach the store even when the fleet size does not change.
            $now = microtime(true);
            if ($changed || ($now - $this->lastStatusAt) >= self::STATUS_INTERVAL) {
                $this->lastStatusAt = $now;
                $onChange();
            }

            usleep((int) (self::TICK * 1_000_000));
            pcntl_signal_dispatch();
        }

        return $this->finalState;
    }

    // -------------------------------------------------------------------------
    // Reaping and deadline enforcement (runs every tick)
    // -------------------------------------------------------------------------

    /**
     * One reaping pass: harvests exited workers (routing each to {@see handleExit()}),
     * SIGKILLs a RETIRING worker past its drain deadline, and re-forks a RESTARTING
     * slot once its back-off has elapsed.
     */
    private function reapAndEnforce(callable $onChange): void
    {
        $now = microtime(true);
        foreach ($this->slots as $slot) {
            if ($slot->state->isAlive()) {
                $res = pcntl_waitpid($slot->pid, $status, WNOHANG);
                if ($res === $slot->pid) {
                    $crashed = !(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
                    $this->handleExit($slot, $crashed, $onChange);
                } elseif ($slot->state === SlotState::RETIRING && $now > $slot->deadline) {
                    // Outlived its drain deadline (or a force-stop collapsed it) — SIGKILL.
                    @posix_kill($slot->pid, SIGKILL);
                    $slot->state = SlotState::KILLING;
                }
            } elseif ($slot->state === SlotState::RESTARTING && !$this->stop) {
                if ($now >= $slot->restartAt) {
                    $this->forkInto($slot, $onChange);
                }
            }
        }
    }

    /**
     * Reacts to a worker exit by slot intent: an intentionally retired worker is
     * freed (never refilled); an unexpected death is restarted per policy.
     */
    private function handleExit(Slot $slot, bool $crashed, callable $onChange): void
    {
        $idx = $slot->index;
        $pid = $slot->pid;
        $intentional = $this->stop
            || $slot->state === SlotState::RETIRING
            || $slot->state === SlotState::KILLING;

        $this->fireWorkerExit($idx, $pid, $intentional ? false : $crashed);

        if ($intentional) {
            $this->free($slot);
            $onChange();
            return;
        }

        $restart = $this->restartPolicy();
        if ($restart->shouldRestart($crashed)) {
            $this->totalRestarts++;
            $slot->restarts++;
            if ($restart->maxRestarts > 0 && $this->totalRestarts >= $restart->maxRestarts) {
                $this->finalState = ProcessState::FAILED;
                // This worker is already dead and reaped — free its slot here, then
                // stop the rest. Leaving it for beginStop() would mark an already
                // reaped slot RETIRING, and it would never drain (aliveCount stuck).
                $this->free($slot);
                $this->beginStop();
                $onChange();
                return;
            }
            // Restart into the SAME slot so worker#{n} stays stable; back off first.
            $this->clearWorkerRecord($idx);
            $slot->state = SlotState::RESTARTING;
            $slot->pid = 0;
            $slot->startedAt = 0;
            $slot->restartAt = microtime(true) + $this->backoff($restart->backoff, $slot->restarts);
        } else {
            // Policy declined to replace this death (NEVER, or a clean exit under
            // ON_FAILURE): retire the slot terminally. It counts as committed, so
            // reconcile does NOT immediately refill it — which would bypass the
            // back-off and the policy and could crash-loop.
            $this->retirePermanently($slot);
        }
        $onChange();
    }

    // -------------------------------------------------------------------------
    // Reconcile — drive the committed fleet to the (damped) desired size
    // -------------------------------------------------------------------------

    /**
     * Drives the committed fleet toward the damped desired size: scales up or down
     * by at most `scaleStep`, gated by `cooldown`. Skipped entirely while stopping.
     */
    private function reconcile(callable $onChange): void
    {
        $policy = $this->scalingPolicy();
        $now = microtime(true);
        if (($now - $this->lastScaleAt) < max(0.0, $policy->cooldown)) {
            return; // cooldown gate between actions
        }

        $committed = $this->committedCount();
        $desired = $this->effectiveDesired($committed, $policy);
        if ($desired === $committed) {
            return;
        }

        $step = $policy->scaleStep;
        $from = $committed;

        if ($desired > $committed) {
            $need = $desired - $committed;
            if ($step > 0) {
                $need = min($need, $step);
            }
            $added = $this->scaleUp($need, $onChange);
            if ($added > 0) {
                $this->lastScaleAt = $now;
                $this->fireScale($from, $from + $added);
                $onChange();
            }
        } else {
            $excess = $committed - $desired;
            if ($step > 0) {
                $excess = min($excess, $step);
            }
            $removed = $this->scaleDown($excess);
            if ($removed > 0) {
                $this->lastScaleAt = $now;
                $this->fireScale($from, $from - $removed);
                $onChange();
            }
        }
    }

    /**
     * Resolves the target size with damping: react to a rise quickly (once it is
     * sustained over scaleUpDelay), shrink only to the high-water demand over the
     * stabilization window — so a transient dip never sheds workers.
     */
    private function effectiveDesired(int $committed, ScalingPolicy $policy): int
    {
        $now = microtime(true);
        $raw = $this->computeDesired();
        $this->desiredHistory[] = [$now, $raw];

        $window = max($policy->scaleUpDelay, $policy->scaleDownStabilization);
        $cutoff = $now - $window;
        $this->desiredHistory = array_values(array_filter(
            $this->desiredHistory,
            static fn(array $e): bool => $e[0] >= $cutoff
        ));

        if ($raw > $committed) {
            // Scale up to the sustained floor of demand; never below current here.
            $floor = $this->windowExtreme($now - max(0.0, $policy->scaleUpDelay), false);
            return max($committed, $floor);
        }
        if ($raw < $committed) {
            // Scale down only to the high-water demand over the window; never above current.
            $ceil = $this->windowExtreme($now - max(0.0, $policy->scaleDownStabilization), true);
            return min($committed, $ceil);
        }
        return $committed;
    }

    /**
     * Max ($max=true) or min of the recorded raw desired values within [$since, now].
     */
    private function windowExtreme(float $since, bool $max): int
    {
        $result = null;
        foreach ($this->desiredHistory as [$t, $v]) {
            if ($t < $since) {
                continue;
            }
            if ($result === null) {
                $result = $v;
            } else {
                $result = $max ? max($result, $v) : min($result, $v);
            }
        }
        return $result ?? $this->computeDesired();
    }

    /**
     * Grows the fleet by up to $need: reclaim still-draining workers first
     * (anti-flap), then spawn on free slots, allocating new ones if short.
     */
    private function scaleUp(int $need, callable $onChange): int
    {
        $added = 0;
        foreach ($this->slots as $slot) {
            if ($added >= $need) {
                break;
            }
            if ($slot->state === SlotState::RETIRING) {
                $slot->state = SlotState::RUNNING;
                $slot->deadline = INF;
                $added++;
            }
        }
        foreach ($this->slots as $slot) {
            if ($added >= $need) {
                break;
            }
            if ($slot->state === SlotState::EMPTY) {
                $this->forkInto($slot, $onChange);
                $added++;
            }
        }
        while ($added < $need) {
            $idx = $this->nextFreeIndex();
            $this->slots[$idx] = new Slot($idx);
            $this->forkInto($this->slots[$idx], $onChange);
            $added++;
        }
        return $added;
    }

    /**
     * Shrinks the fleet by up to $excess: cancel back-off restarts first (no live
     * process), then retire live workers gracefully, IDLE ones first.
     */
    private function scaleDown(int $excess): int
    {
        $removed = 0;
        // 0) shed already-dead RETIRED slots first — reclaiming them is free.
        foreach ($this->slots as $slot) {
            if ($removed >= $excess) {
                break;
            }
            if ($slot->state === SlotState::RETIRED) {
                $this->free($slot);
                $removed++;
            }
        }
        // 1) cancel pending back-off restarts (no live process yet).
        foreach ($this->slots as $slot) {
            if ($removed >= $excess) {
                break;
            }
            if ($slot->state === SlotState::RESTARTING) {
                $this->free($slot);
                $removed++;
            }
        }
        $grace = $this->graceSeconds();
        foreach ($this->pickVictims($excess - $removed) as $slot) {
            $this->retire($slot, $grace);
            $removed++;
        }
        return $removed;
    }

    /**
     * Picks up to $k live workers to retire — IDLE first (reclaimed at once, no
     * work lost), then BUSY by highest slot (keeping low slots stable). A BUSY
     * victim still drains gracefully; this only orders which go first.
     *
     * @return list<Slot>
     */
    private function pickVictims(int $k): array
    {
        if ($k <= 0) {
            return [];
        }
        $candidates = [];
        foreach ($this->slots as $slot) {
            if ($slot->state === SlotState::RUNNING || $slot->state === SlotState::STARTING) {
                $candidates[] = $slot;
            }
        }
        usort($candidates, static function (Slot $a, Slot $b): int {
            $ai = $a->activity === Activity::IDLE ? 0 : 1;
            $bi = $b->activity === Activity::IDLE ? 0 : 1;
            return ($ai <=> $bi) ?: ($b->index <=> $a->index);
        });
        return array_slice($candidates, 0, $k);
    }

    // -------------------------------------------------------------------------
    // Stop sequence
    // -------------------------------------------------------------------------

    /**
     * First stop signal drains the whole fleet; a second one forces it down now.
     */
    private function requestFleetStop(): void
    {
        if (!$this->stop) {
            $this->beginStop();
        } else {
            $this->forceStop();
        }
    }

    /**
     * Freezes reconcile and retires the whole fleet at once (a full stop is
     * "retire every slot"), reusing the graceful drain + deadline machinery.
     */
    private function beginStop(): void
    {
        $this->stop = true;
        $grace = $this->graceSeconds();
        foreach ($this->slots as $slot) {
            if ($slot->state === SlotState::STARTING || $slot->state === SlotState::RUNNING) {
                $this->retire($slot, $grace);
            } elseif ($slot->state === SlotState::RESTARTING) {
                $this->free($slot); // cancel a pending restart
            }
        }
    }

    /**
     * Collapses every drain deadline to now, so the next enforcement pass SIGKILLs
     * any worker still alive — the operator's "stop now" on a repeated signal.
     */
    private function forceStop(): void
    {
        $now = microtime(true);
        foreach ($this->slots as $slot) {
            if ($slot->state->isAlive()) {
                $slot->deadline = $now;
            }
        }
    }

    /**
     * SIGHUP handling: runs the master's {@see Daemon::onReload()} hook and
     * forwards the signal to every worker. Reload, not stop.
     */
    private function reload(): void
    {
        $this->fireReload();
        $this->forward(SIGHUP);
    }

    /**
     * Forwards a control signal to every live worker; the supervisor stays up.
     */
    private function forward(int $signo): void
    {
        foreach ($this->slots as $slot) {
            if ($slot->pid > 0 && $slot->state->isAlive()) {
                @posix_kill($slot->pid, $signo);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Slot transitions
    // -------------------------------------------------------------------------

    /**
     * Forks a worker into the slot. In the child, inherited signal handlers are
     * reset so the worker's own runtime installs its own — then the body runs and
     * its outcome maps to the exit code.
     */
    private function forkInto(Slot $slot, callable $onChange): void
    {
        $slot->startedAt = time();
        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_DFL);
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_signal(SIGUSR2, SIG_DFL);
            try {
                $this->bootWorker($slot->index);
                exit(0);
            } catch (\Throwable) {
                // The worker logs its own failure; the non-zero code is the signal.
                exit(1);
            }
        }
        $slot->pid = $pid;
        $slot->state = SlotState::STARTING;
        $slot->deadline = INF;
        $slot->activity = Activity::IDLE;
        $slot->heartbeatAt = 0;
        $slot->killed = false;
        $this->fireWorkerStart($slot->index, $pid);
        $onChange();
    }

    /**
     * Retires a worker: SIGTERM to begin a graceful drain, and stamp the deadline
     * the enforcement pass uses to SIGKILL a straggler (INF = wait forever).
     */
    private function retire(Slot $slot, float $grace): void
    {
        $slot->state = SlotState::RETIRING;
        @posix_kill($slot->pid, SIGTERM);
        $slot->deadline = $grace > 0 ? microtime(true) + $grace : INF;
    }

    /**
     * Returns a slot to EMPTY (reusable), clearing its heartbeat record.
     */
    private function free(Slot $slot): void
    {
        $this->clearWorkerRecord($slot->index);
        $slot->state = SlotState::EMPTY;
        $slot->pid = 0;
        $slot->deadline = INF;
        $slot->restartAt = 0.0;
        $slot->startedAt = 0;
        $slot->activity = Activity::IDLE;
        $slot->heartbeatAt = 0;
        $slot->killed = false;
    }

    /**
     * Terminally retires a slot whose worker died and the policy declined to
     * replace. It counts as committed (so reconcile leaves it alone) until a
     * scale-down reclaims it.
     */
    private function retirePermanently(Slot $slot): void
    {
        $this->clearWorkerRecord($slot->index);
        $slot->state = SlotState::RETIRED;
        $slot->pid = 0;
        $slot->deadline = INF;
        $slot->restartAt = 0.0;
        $slot->startedAt = 0;
        $slot->activity = Activity::IDLE;
        $slot->heartbeatAt = 0;
        $slot->killed = false;
    }

    // -------------------------------------------------------------------------
    // Worker observation
    // -------------------------------------------------------------------------

    /**
     * Refreshes each live slot's activity from the worker heartbeat, and promotes
     * STARTING → RUNNING on the first heartbeat seen. Returns whether any slot's
     * state or activity changed (so the caller can persist the fleet view).
     */
    private function refreshWorkers(): bool
    {
        $changed = false;
        foreach ($this->slots as $slot) {
            if (
                $slot->state === SlotState::STARTING
                || $slot->state === SlotState::RUNNING
                || $slot->state === SlotState::RETIRING
            ) {
                $record = $this->workerRecord($slot->index);
                if ($record !== null) {
                    if ($slot->activity !== $record->activity) {
                        $slot->activity = $record->activity;
                        $changed = true;
                    }
                    $slot->heartbeatAt = $record->heartbeatAt;
                    if ($slot->state === SlotState::STARTING) {
                        $slot->state = SlotState::RUNNING;
                        $changed = true;
                    }
                }
            }
        }
        return $changed;
    }

    /**
     * Kills a worker whose heartbeat has gone silent past the liveness timeout —
     * a wedged worker (deadlock, hung I/O, a boot that never finishes) that a
     * plain pid check misses. The reap then restarts it through the crash path
     * (back-off + policy). Disabled when the timeout is 0.
     */
    private function watchdog(): void
    {
        $timeout = $this->livenessTimeout();
        if ($timeout <= 0.0) {
            return;
        }
        $now = time();
        foreach ($this->slots as $slot) {
            if ($slot->killed) {
                continue;
            }
            if ($slot->state !== SlotState::RUNNING && $slot->state !== SlotState::STARTING) {
                continue;
            }
            // A running worker is judged by its last heartbeat; a STARTING worker
            // that has not beat yet is judged from when it was forked.
            $last = $slot->heartbeatAt > 0 ? $slot->heartbeatAt : $slot->startedAt;
            if ($last > 0 && ($now - $last) > $timeout) {
                $this->logger->warning(
                    'worker#' . ($slot->index + 1) . " [PID {$slot->pid}] hung — no heartbeat for "
                    . ($now - $last) . "s (> {$timeout}s); killing."
                );
                @posix_kill($slot->pid, SIGKILL);
                $slot->killed = true;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Introspection (consumed by the Daemon status writer)
    // -------------------------------------------------------------------------

    /**
     * @return list<WorkerStatus> One entry per non-empty slot.
     */
    private function snapshot(): array
    {
        $out = [];
        foreach ($this->slots as $slot) {
            if ($slot->state === SlotState::EMPTY) {
                continue;
            }
            $out[] = new WorkerStatus(
                slot: $slot->index,
                pid: $slot->pid,
                state: $slot->state,
                activity: $slot->activity,
                startedAt: $slot->startedAt,
                restarts: $slot->restarts,
            );
        }
        return $out;
    }

    /** Total worker restarts across the fleet's lifetime (for {@see DaemonStatus}). */
    private function restartsTotal(): int
    {
        return $this->totalRestarts;
    }

    /** Whether a stop is in progress (reconcile frozen, fleet draining). */
    private function isStopping(): bool
    {
        return $this->stop;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Slots that are or will be running — the size reconcile drives to desired. */
    private function committedCount(): int
    {
        $n = 0;
        foreach ($this->slots as $slot) {
            if ($slot->state->isCommitted()) {
                $n++;
            }
        }
        return $n;
    }

    /** Slots with a live OS process attached. */
    private function aliveCount(): int
    {
        $n = 0;
        foreach ($this->slots as $slot) {
            if ($slot->state->isAlive()) {
                $n++;
            }
        }
        return $n;
    }

    /** Lowest free slot index (reuses an EMPTY slot, else appends a new one). */
    private function nextFreeIndex(): int
    {
        $i = 0;
        while (isset($this->slots[$i]) && $this->slots[$i]->state !== SlotState::EMPTY) {
            $i++;
        }
        return $i;
    }

    /**
     * Exponential back-off `base × 2^(failures-1)`, capped at {@see BACKOFF_CAP};
     * 0 for no failures or no base.
     */
    private function backoff(float $base, int $failures): float
    {
        if ($base <= 0.0 || $failures <= 0) {
            return 0.0;
        }
        return min($base * (2 ** ($failures - 1)), self::BACKOFF_CAP);
    }
}
