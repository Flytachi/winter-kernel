<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Process\BeforeFork;
use Flytachi\Winter\Kernel\Process\Internal\Termination;
use Flytachi\Winter\Kernel\Process\ProcessAlreadyRunningException;
use Psr\Log\LoggerInterface;

/**
 * Runs an application's components and keeps them running: the HTTP server, processes,
 * daemons, the scheduler, WebSocket servers and outside programs. It is the application
 * itself — PID 1 in a container — with or without an HTTP server.
 *
 * Each component runs in a process of its own, and its life has two phases:
 *
 * - **Starting**, until it says it is ready ({@see ComponentChannel}). A component that
 *   breaks here — or is not ready within its `startTimeout` — is broken, not unlucky:
 *   it is not retried.
 * - **Running**. A crash here is retried after 1, 2, 4… seconds, up to `retries` times
 *   within {@see Component::RETRY_WINDOW}; a component that stayed up that long starts
 *   counting afresh. A retry that breaks while starting is one of those attempts. A
 *   daemon is never retried: it supervises its own workers.
 *
 * A component that cannot run any more stops the application when it is `critical`
 * (the default) — every other component is stopped and {@see run()} returns 1 — and is
 * left down otherwise, marked failed, while the rest runs on.
 *
 * Not failures: a component stopped on request (a stop signal), a process whose body
 * returned (its work is done). A scheduler, WebSocket or daemon whose loop ends without
 * being asked to has crashed. A process whose work is done leaves no record behind: it
 * shows as completed here, and can be started again.
 *
 * A process class the application was not declared with, started while it runs (from
 * the console, with `dispatch()`), is taken in ({@see Component::adopted()}): it runs
 * once — never restarted, never critical — and is let go when it ends or is stopped;
 * one that failed stays listed, with why, until it is started again.
 *
 * @internal Started by {@see \Flytachi\Winter\Kernel\WinterApplication} — not for application code.
 */
final class Supervisor
{
    /** Seconds between looks at the components. */
    private const float TICK = 0.1;
    /** Seconds a component that ran out of start time has to stop before SIGKILL. */
    private const float KILL_AFTER = 10.0;
    /** Seconds between looks for requests. */
    private const float COMMAND_INTERVAL = 0.5;
    /** Seconds between looks at an outside process holding a component. */
    private const float HELD_INTERVAL = 1.0;
    /** Seconds between publications when nothing changed: the application is still here. */
    private const float HEARTBEAT = 10.0;

    /** @var list<Unit> */
    private array $units = [];
    private bool $stopping = false;
    private int $exitCode = 0;
    private float $commandsAt = 0.0;
    /** What was published last, to publish only changes. */
    private string $published = '';
    private float $publishedAt = 0.0;
    /** A component asked for the whole application to restart in place (`call run dev`). */
    private bool $reloadRequested = false;
    /** Why the application is stopping — a signal's name, a critical failure, a reload. */
    private string $stopReason = '';
    private float $stopAt = 0.0;
    /** How many components were running when the stop began. */
    private int $stopCount = 0;
    /** Stops the components if the application dies without stopping them. */
    private ?Sentinel $sentinel = null;
    /** A second stop signal arrived: what is left is killed at once. */
    private bool $forceRequested = false;
    /** What is left was killed — by a second signal or the stop budget. */
    private bool $forced = false;
    /** How many components a SIGHUP reached, until the loop logs it. */
    private ?int $reloadedCount = null;
    private bool $stopAnnounced = false;
    /** The signal mask from before a fork, restored on both sides once it is safe. */
    private array $maskBeforeFork = [];

    /** The signals the supervisor handles — and holds back around a fork. */
    private const array SIGNALS = [SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2];

    /**
     * @param list<Component> $components
     * @param \Closure(Component): void $launch Runs a component in the current process.
     * @param \Closure(string, \Throwable): void $report Says, loudly, why a component threw.
     * @param \Closure(string): void $announce Says one line, loudly — a critical failure.
     * @param ComponentRecords $records The records of components that are down.
     * @param \Closure(Component): string $name How logs name a component.
     * @param (\Closure(): list<ControlRequest>)|null $commands The pending requests
     *     ({@see ComponentControl::pending()}); none are taken when absent.
     * @param (\Closure(list<array<string, mixed>>): void)|null $publish Receives where every
     *     component stands whenever that changes — see {@see snapshot()}.
     * @param float|null $stopBudget Seconds a stop waits for the components before it
     *     kills what is left; null waits for good.
     */
    public function __construct(
        array $components,
        private readonly \Closure $launch,
        private readonly \Closure $report,
        private readonly \Closure $announce,
        private readonly ComponentRecords $records,
        private readonly \Closure $name,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $commands = null,
        private readonly ?\Closure $publish = null,
        private readonly ?float $stopBudget = null,
    ) {
        foreach ($components as $component) {
            $this->units[] = new Unit($component, $name($component));
        }
    }

    /**
     * Runs until the application is stopped, or a critical component fails.
     *
     * @param bool $untilDone Also return once every component has finished its work and
     *   nothing is left to run. A component that runs for good — the HTTP server among
     *   them — keeps it up until a stop.
     * @return int 0 when stopped, 1 when a critical component failed.
     */
    public function run(bool $untilDone): int
    {
        // The Swoole runtime hooks are left exactly as they are. A component inherits them
        // unset, and Coroutine\run() then switches all of them on inside its coroutines —
        // set them here, even to 0, and that no longer happens: spawn() would do its I/O
        // one task at a time. Nor may hooks be switched on here for the components: a
        // process that ran hooked I/O outside a coroutine — a daemon's master — hands its
        // workers a half-built async runtime, and their coroutines stall.

        // Forked before anything else, with nothing of the supervisor's to inherit.
        $this->sentinel = Sentinel::spawn();

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop('SIGTERM'));
        pcntl_signal(SIGINT, fn() => $this->stop('SIGINT'));
        pcntl_signal(SIGHUP, fn() => $this->reload());
        // What a Swoole server answers to — a graceful reload of its workers — reaches the
        // HTTP server, as it did when the server was this process. To a process they mean
        // what its own onUser1()/onUser2() make of them: sent to its own PID.
        foreach ([SIGUSR1, SIGUSR2] as $signal) {
            pcntl_signal($signal, fn() => $this->forward($signal, ComponentKind::Http));
        }

        while (true) {
            $now = microtime(true);
            // Said here, not in the signal handler: a handler runs between any two
            // opcodes, the middle of another log write among them.
            if ($this->stopping && !$this->stopAnnounced) {
                $this->announceStop();
            }
            if ($this->stopping && !$this->forced) {
                if ($this->forceRequested) {
                    $this->killWhatIsLeft('a second stop signal');
                } elseif ($this->stopBudget !== null && $now - $this->stopAt >= $this->stopBudget) {
                    $this->killWhatIsLeft(sprintf('not stopped within %ss', $this->stopBudget));
                }
            }
            if ($this->reloadedCount !== null) {
                $this->logger->info(sprintf('Application reloading (SIGHUP): %d component(s)', $this->reloadedCount));
                $this->reloadedCount = null;
            }
            if (!$this->stopping) {
                $this->takeCommands($now);
                $this->watchHeld($now);
                $this->startDue($now);
            }
            $this->listen();
            $this->reap();
            $this->enforceStartTimeouts(microtime(true));
            $this->publish();

            if ($this->alive() === 0 && ($this->stopping || ($untilDone && $this->done()))) {
                $this->forgetAll();
                $this->announceEnd();
                $this->sentinel?->done();
                return $this->exitCode;
            }
        }
    }

    /**
     * The first line of a stop: why, and how many components it waits for. A container's
     * log otherwise ended on whatever a component said last, and a stop could not be told
     * from a crash.
     */
    private function announceStop(): void
    {
        $this->stopAnnounced = true;
        if ($this->reloadRequested) {
            $this->logger->info('Application reloading.');
            return;
        }
        $this->logger->info(sprintf('Application stopping (%s): %d component(s)', $this->stopReason, $this->stopCount));
    }

    /**
     * The end of a stop that waits no longer: SIGKILL to the components still running,
     * their whole groups, named in the log so it is known whose work was cut.
     */
    private function killWhatIsLeft(string $why): void
    {
        $this->forced = true;
        $left = [];
        foreach ($this->units as $unit) {
            if ($unit->pid > 0) {
                $left[] = $unit->name;
                @posix_kill(-$unit->pid, SIGKILL);
                @posix_kill($unit->pid, SIGKILL);
            }
        }
        if ($left !== []) {
            $this->logger->warning(sprintf('Application stop: %s — killing %s.', $why, implode(', ', $left)));
        }
    }

    /** The last line: how the stop went, or that the work is done. */
    private function announceEnd(): void
    {
        if (!$this->stopping) {
            $this->logger->info('Application finished: every component has done its work.');
            return;
        }
        if (!$this->stopAnnounced) {
            $this->announceStop();
        }
        if ($this->reloadRequested) {
            return;
        }
        $took = microtime(true) - $this->stopAt;
        $this->logger->info(sprintf(
            'Application stopped%s%s in %s',
            $this->exitCode !== 0 ? ' (critical failure)' : '',
            $this->forced ? ' (forced)' : '',
            $took < 1.0 ? sprintf('%d ms', (int) round($took * 1000)) : sprintf('%.1fs', $took),
        ));
    }

    /** Whether a component asked for the application to restart in place. */
    public function reloadRequested(): bool
    {
        return $this->reloadRequested;
    }

    /**
     * SIGHUP: every component reloads in its own way, and the application stays up. The
     * HTTP server is sent SIGUSR1, a graceful restart of its workers — a Swoole server
     * has no SIGHUP handler. A process gets SIGHUP: its onReload(), a scheduler's
     * re-read of the schedule, a daemon's restart of its workers. An outside program
     * gets nothing: SIGHUP's default is to end it.
     */
    private function reload(): void
    {
        if ($this->stopping) {
            return;
        }
        $count = 0;
        foreach ($this->units as $unit) {
            if ($unit->pid <= 0 || $unit->component->kind === ComponentKind::Command) {
                continue;
            }
            @posix_kill($unit->pid, $unit->component->kind === ComponentKind::Http ? SIGUSR1 : SIGHUP);
            $count++;
        }
        $this->reloadedCount = $count;
    }

    /** Passes a signal on to the running components of one kind. */
    private function forward(int $signal, ComponentKind $kind): void
    {
        foreach ($this->units as $unit) {
            if ($unit->pid > 0 && $unit->component->kind === $kind) {
                @posix_kill($unit->pid, $signal);
            }
        }
    }

    /**
     * Stops every component: the application is shutting down.
     *
     * Every component gets SIGTERM, whatever the application received. SIGTERM is the
     * one stop signal each of them handles gracefully; a Swoole server has no handler
     * for SIGINT, so Ctrl+C passed on as it came killed it outright — requests in
     * flight cut, the workers' pools never closed.
     *
     * @param string $reason Why, for the log: a signal's name, `critical failure`, `reload`.
     */
    private function stop(string $reason): void
    {
        if ($this->stopping) {
            // Ctrl+C again, a second `kill`: whoever sends it does not want to wait.
            if ($reason === 'SIGTERM' || $reason === 'SIGINT') {
                $this->forceRequested = true;
            }
            return;
        }
        $this->stopping = true;
        $this->stopReason = $reason;
        $this->stopCount = $this->alive();
        $now = microtime(true);
        $this->stopAt = $now;
        foreach ($this->units as $unit) {
            if ($unit->pid > 0) {
                @posix_kill($unit->pid, SIGTERM);
                // A framework component ends within its own grace; an outside program
                // may ignore the signal altogether.
                if ($unit->component->kind === ComponentKind::Command) {
                    $unit->killAt = $now + $unit->component->grace;
                }
            }
        }
    }

    // ── Starting ──────────────────────────────────────────────────────────────

    private function startDue(float $now): void
    {
        foreach ($this->units as $unit) {
            // A stop that arrived while the components before this one were starting:
            // nothing more is started — it would never be told to stop.
            if ($this->stopping) {
                return;
            }
            if ($unit->state === UnitState::Waiting && $now >= $unit->startAt) {
                $this->start($unit);
            }
        }
    }

    private function start(Unit $unit): void
    {
        $unit->resetRun();
        $outsider = $this->records->outsider($unit->component);
        if ($outsider !== null) {
            $this->hold($unit, $outsider);
            $this->answer($unit, 0, $unit->reason);
            return;
        }
        // What the supervisor recorded of it while it was down gives way to its own record.
        $this->records->forget($unit->component);

        // A connection the supervisor opened would be shared with the child, and the
        // child's fork reset would end the supervisor's session with it. A connection that
        // cannot be closed yet (a transaction) holds the start back for a second.
        try {
            BeforeFork::runAll();
        } catch (\Throwable $e) {
            $this->logger->error("{$unit->name} not started yet: {$e->getMessage()}; retrying in 1s.");
            $unit->startAt = microtime(true) + 1.0;
            return;
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->answer($unit, 0, 'could not open its channel');
            $this->fail($unit, 'could not open its channel');
            return;
        }

        // The stop and reload signals wait until the parent knows the child's pid and the
        // child has dropped the supervisor's handlers. Delivered in between, a stop would
        // miss the new component — or run the supervisor's own stop inside the child,
        // which then carried on as the component and never stopped.
        pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS, $this->maskBeforeFork);
        // Checked with the signals held: a stop that came while this start was being
        // prepared leaves the component unstarted, with no window left for another.
        if ($this->stopping) {
            pcntl_sigprocmask(SIG_SETMASK, $this->maskBeforeFork);
            fclose($pair[0]);
            fclose($pair[1]);
            return;
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            pcntl_sigprocmask(SIG_SETMASK, $this->maskBeforeFork);
            fclose($pair[0]);
            fclose($pair[1]);
            $this->answer($unit, 0, 'could not be forked');
            $this->fail($unit, 'could not be forked');
            return;
        }
        if ($pid === 0) {
            // A group of its own: whatever the component forks — a daemon's workers, a
            // Swoole server's manager and workers, a script's children — goes with it.
            @posix_setpgid(0, 0);
            fclose($pair[0]);
            if ($unit->component->kind === ComponentKind::Command) {
                fclose($pair[1]);
                $this->becomeProgram($unit);
            }
            $this->becomeComponent($unit, $pair[1]);
        }

        // Set from both sides: whichever runs first, the group exists before it is needed.
        @posix_setpgid($pid, $pid);
        fclose($pair[1]);
        stream_set_blocking($pair[0], false);
        $unit->pid = $pid;
        $this->sentinel?->track($pid);
        $unit->socket = $pair[0];
        $unit->state = UnitState::Starting;
        // A stop held back by the fork is delivered here, and now reaches this one too.
        pcntl_sigprocmask(SIG_SETMASK, $this->maskBeforeFork);
        $this->answer($unit, $pid);
        $unit->startedAt = microtime(true);
        $timeout = $unit->component->startTimeout;
        $unit->readyDeadline = $timeout > 0 ? $unit->startedAt + $timeout : 0.0;
        // An outside program says nothing: it is ready as soon as it runs.
        if ($unit->component->kind === ComponentKind::Command) {
            $this->ready($unit);
        }
    }

    /**
     * The child's side of an outside program: it becomes the program. Never returns.
     *
     * Looked up on PATH unless it is a path; run in the project root with the
     * application's environment, the variables `.env` loaded included.
     */
    private function becomeProgram(Unit $unit): never
    {
        $this->dropSupervisorSignals(program: true);
        foreach ($this->units as $other) {
            if ($other->socket !== null) {
                @fclose($other->socket);
            }
        }
        $root = Kernel::$pathRoot ?? '';
        if ($root !== '') {
            @chdir($root);
        }

        $command = $unit->component->command;
        [$program, $args] = str_contains($command[0], '/')
            ? [$command[0], array_slice($command, 1)]
            : ['/usr/bin/env', $command];
        @pcntl_exec($program, $args, self::environment());

        // Only reached when the program could not be run.
        @fwrite(STDERR, "winter: {$unit->name} could not be run: " . pcntl_strerror(pcntl_get_last_error()) . "\n");
        Termination::leave(127);
        throw new \LogicException('unreachable');
    }

    /**
     * The child's first step: the supervisor's handlers go, then the signals held back
     * around the fork are let through. In that order — let through first, a stop would
     * run the supervisor's handler in the child. And they must be let through: the mask
     * survives exec, and a program started with SIGTERM blocked could never be stopped.
     *
     * A component ignores SIGHUP/SIGUSR1/SIGUSR2 until it sets its own handlers: their
     * default is to end the process, and a reload arriving that early would kill it. An
     * outside program gets the defaults, SIGPIPE included — an ignored signal stays
     * ignored across exec.
     */
    private function dropSupervisorSignals(bool $program): void
    {
        foreach (self::SIGNALS as $signal) {
            $stop = $signal === SIGTERM || $signal === SIGINT;
            pcntl_signal($signal, $program || $stop ? SIG_DFL : SIG_IGN);
        }
        if ($program) {
            // PHP's CLI ignores SIGPIPE, and an ignored signal survives exec: a script's
            // `producer | head` would end in "Broken pipe" errors instead of quietly.
            pcntl_signal(SIGPIPE, SIG_DFL);
        }
        pcntl_sigprocmask(SIG_SETMASK, $this->maskBeforeFork);
        // What only the application may hold: its lock, and its end of the sentinel's
        // link — kept here, either would outlive the application with this child.
        ApplicationRuntime::dropInheritedLock();
        $this->sentinel?->forget();
    }

    /** @return array<string, string> The environment, with what `.env` put in $_ENV. */
    private static function environment(): array
    {
        $env = getenv();
        foreach ($_ENV as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $env[$name] ??= (string) $value;
            }
        }
        return $env;
    }

    /**
     * The child's side of a start: it leaves the supervisor's business behind and runs
     * the component. Never returns.
     *
     * @param resource $socket
     */
    private function becomeComponent(Unit $unit, mixed $socket): never
    {
        $this->dropSupervisorSignals(program: false);
        foreach ($this->units as $other) {
            if ($other->socket !== null) {
                @fclose($other->socket);
            }
        }
        ComponentChannel::attach($socket);

        $code = 0;
        try {
            ($this->launch)($unit->component);
        } catch (ProcessAlreadyRunningException) {
            // Started outside the application in the meantime: not broken, only taken.
            ComponentChannel::held();
            $code = 3;
        } catch (\Throwable $e) {
            ($this->report)($unit->name . ' failed', $e);
            $code = 1;
        }
        // Not exit(): in a process forked from one Swoole created, exit() only throws, and
        // this child would carry on into the supervisor's own loop.
        Termination::leave($code);
        throw new \LogicException('unreachable');
    }

    // ── Watching ──────────────────────────────────────────────────────────────

    /** Reads what the components said; waits up to one tick. */
    private function listen(): void
    {
        $read = [];
        foreach ($this->units as $unit) {
            if ($unit->socket !== null) {
                $read[] = $unit->socket;
            }
        }
        // stream_select() is fatal outside a coroutine once the runtime hooks are on — not
        // the case unless the application switched them on in this process itself; then the
        // channels are only polled.
        $hooked = extension_loaded('swoole') && \Swoole\Runtime::getHookFlags() !== 0;
        if ($read === [] || $hooked) {
            usleep((int) (self::TICK * 1_000_000));
        } else {
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, (int) (self::TICK * 1_000_000)) < 1) {
                return;
            }
        }
        foreach ($this->units as $unit) {
            if ($unit->socket === null || !in_array($unit->socket, $read, true)) {
                continue;
            }
            $data = (string) @fread($unit->socket, 64);
            if (str_contains($data, ComponentChannel::READY)) {
                $this->ready($unit);
            }
            if (str_contains($data, ComponentChannel::STOPPED)) {
                $unit->stopRequested = true;
            }
            if (str_contains($data, ComponentChannel::FAILED)) {
                $unit->gaveUp = true;
            }
            if (str_contains($data, ComponentChannel::HELD)) {
                $unit->held = true;
            }
            if (str_contains($data, ComponentChannel::RELOAD) && !$this->stopping) {
                $this->logger->info("{$unit->name} asked for a restart of the application.");
                $this->reloadRequested = true;
                $this->stop('reload');
            }
        }
    }

    private function ready(Unit $unit): void
    {
        if ($unit->state !== UnitState::Starting) {
            return;
        }
        $unit->reason = '';
        $unit->state = UnitState::Running;
        $unit->everReady = true;
        $unit->readyDeadline = 0.0;
        $unit->heldTold = false;
        $this->logger->info("{$unit->name} is up.");
    }

    private function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if ($this->sentinel !== null && $pid === $this->sentinel->pid) {
                // Gone before the application (the OOM killer, a stray kill): the run goes
                // on, but no longer covered — said once, so it is not a surprise later.
                $this->logger->warning(
                    "The sentinel (PID {$pid}) is gone: if the application dies now without "
                    . 'stopping its components, nothing will stop them.'
                );
                $this->sentinel = null;
                continue;
            }
            foreach ($this->units as $unit) {
                if ($unit->pid === $pid) {
                    $this->exited($unit, $status);
                    break;
                }
            }
        }
    }

    private function enforceStartTimeouts(float $now): void
    {
        foreach ($this->units as $unit) {
            if ($unit->killAt > 0.0 && $unit->pid > 0 && $now >= $unit->killAt) {
                @posix_kill($unit->pid, SIGKILL);
                $unit->killAt = 0.0;
            }
        }
        foreach ($this->units as $unit) {
            if ($unit->state !== UnitState::Starting || $unit->readyDeadline <= 0.0 || $now < $unit->readyDeadline) {
                continue;
            }
            if ($unit->timedOutAt === 0.0) {
                $unit->timedOutAt = $now;
                @posix_kill($unit->pid, SIGTERM);
            } elseif ($now - $unit->timedOutAt >= self::KILL_AFTER) {
                @posix_kill($unit->pid, SIGKILL);
            }
        }
    }

    // ── Deciding ──────────────────────────────────────────────────────────────

    private function exited(Unit $unit, int $status): void
    {
        // What the component left behind — children of a process killed outright, a Swoole
        // worker still holding the port — goes now: they would outlive it unwatched, and a
        // restart would meet them.
        @posix_kill(-$unit->pid, SIGKILL);
        $this->sentinel?->untrack($unit->pid);

        if ($unit->socket !== null) {
            // Whatever it said last may still be unread.
            $tail = (string) @stream_get_contents($unit->socket);
            if (str_contains($tail, ComponentChannel::READY)) {
                $this->ready($unit);
            }
            $unit->stopRequested = $unit->stopRequested || str_contains($tail, ComponentChannel::STOPPED);
            $unit->gaveUp = $unit->gaveUp || str_contains($tail, ComponentChannel::FAILED);
            $unit->held = $unit->held || str_contains($tail, ComponentChannel::HELD);
            if (str_contains($tail, ComponentChannel::RELOAD) && !$this->stopping) {
                $this->reloadRequested = true;
                $this->stop('reload');
            }
            @fclose($unit->socket);
        }
        $wasReady = $unit->state === UnitState::Running;
        $timedOut = $unit->timedOutAt > 0.0;
        $stopRequested = $unit->stopRequested;
        $gaveUp = $unit->gaveUp;
        $held = $unit->held;
        $unit->resetRun();

        if ($this->stopping) {
            $unit->state = UnitState::Stopped;
            return;
        }
        if ($unit->restartPending) {
            $unit->restartPending = false;
            $unit->state = UnitState::Waiting;
            $unit->startAt = 0.0;
            $this->logger->info("{$unit->name} restarting on request.");
            return;
        }
        if ($timedOut) {
            $this->crashed($unit, "was not ready within {$unit->component->startTimeout}s", false);
            return;
        }
        if ($held) {
            $this->hold($unit, (int) $this->records->outsider($unit->component));
            return;
        }
        if ($stopRequested) {
            $this->logger->info("{$unit->name} stopped.");
            if ($unit->component->adopted) {
                $this->letGo($unit);
                return;
            }
            $unit->state = UnitState::Stopped;
            $this->recordStopped($unit);
            return;
        }
        if ($gaveUp) {
            $this->fail($unit, 'gave up');
            return;
        }
        $clean = pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
        $finishes = $unit->component->kind === ComponentKind::Process
            || $unit->component->kind === ComponentKind::Command;
        if ($clean && $finishes) {
            $this->logger->info("{$unit->name} finished its work.");
            if ($unit->component->adopted) {
                $this->letGo($unit);
                return;
            }
            // Nothing is left of it but this line: no record says it is down, and it can
            // be started again like any process.
            $unit->state = UnitState::Completed;
            $this->forget($unit);
            return;
        }
        $this->crashed($unit, self::describe($status, $clean), $wasReady);
    }

    /**
     * @param bool $wasReady Whether this process had become ready before it ended.
     */
    private function crashed(Unit $unit, string $reason, bool $wasReady): void
    {
        // Never ready since the application started: it does not start at all.
        if (!$unit->everReady) {
            $this->fail($unit, "did not start: {$reason}");
            return;
        }

        $now = microtime(true);
        $unit->restarts = array_values(array_filter(
            $unit->restarts,
            static fn(float $at): bool => $now - $at < Component::RETRY_WINDOW,
        ));
        $limit = $unit->component->retries;
        if (count($unit->restarts) >= $limit) {
            $this->fail($unit, $limit === 0
                ? $reason
                : "{$reason}, after {$limit} restarts within " . intdiv(Component::RETRY_WINDOW, 60) . ' minutes');
            return;
        }

        $unit->restarts[] = $now;
        $attempt = count($unit->restarts);
        $delay = 2 ** ($attempt - 1);
        $unit->reason = $reason;
        $unit->state = UnitState::Waiting;
        $unit->startAt = $now + $delay;
        $this->logger->warning(sprintf(
            '%s %s %s; restart %d of %d in %ds.',
            $unit->name,
            $wasReady ? 'crashed:' : 'failed again while starting:',
            $reason,
            $attempt,
            $limit,
            $delay,
        ));
    }

    /** It cannot run any more: stop the application, or leave it down. */
    private function fail(Unit $unit, string $reason): void
    {
        $unit->reason = $reason;
        if ($unit->component->critical) {
            ($this->announce)("{$unit->name} failed: {$reason}. It is critical: the application stops.");
            $unit->state = UnitState::Failed;
            $this->exitCode = 1;
            $this->stop('critical failure');
            return;
        }
        $unit->state = UnitState::Failed;
        $this->logger->error(
            "{$unit->name} failed: {$reason}. It is not critical: the application runs on without it."
        );
        if ($unit->component->adopted) {
            // Listed here with why; its class keeps no record — started again, it is
            // taken in afresh.
            return;
        }
        try {
            $this->records->failed($unit->component);
        } catch (\Throwable) {
            // The log line above already says it.
        }
    }

    // ── Requests and outside holders ──────────────────────────────────────────

    /** Picks up what was asked of the components: start one that is down, restart one. */
    private function takeCommands(float $now): void
    {
        if ($this->commands === null || $now - $this->commandsAt < self::COMMAND_INTERVAL) {
            return;
        }
        $this->commandsAt = $now;
        // A request is the outside world's: nothing in it may bring the application down.
        try {
            $requests = ($this->commands)();
        } catch (\Throwable $e) {
            $this->logger->error("Requests could not be read: {$e->getMessage()}");
            return;
        }
        foreach ($requests as $request) {
            try {
                $this->take($request);
            } catch (\Throwable $e) {
                $this->logger->error("Request for {$request->class} not taken: {$e->getMessage()}");
                $this->reply($request->replyTo, 0, $e->getMessage());
            }
        }
    }

    private function take(ControlRequest $request): void
    {
        $unit = $this->unitOf($request->class);
        if ($unit === null || ($unit->component->adopted && $unit->state === UnitState::Failed)) {
            $this->adopt($request, $unit);
            return;
        }
        if ($request->action === ComponentControl::RESTART) {
            $this->restartOnRequest($unit);
            $unit->replyTo = $request->replyTo ?? $unit->replyTo;
            return;
        }
        $this->startOnRequest($unit, 'started on request');
        match ($unit->state) {
            // Answered once it is forked.
            UnitState::Waiting => $unit->replyTo = $request->replyTo ?? $unit->replyTo,
            UnitState::Starting, UnitState::Running => $this->reply($request->replyTo, $unit->pid),
            default => $this->reply($request->replyTo, 0, $unit->reason !== '' ? $unit->reason : 'not started'),
        };
    }

    /** Tells the request waiting on this unit which process its start became. */
    private function answer(Unit $unit, int $pid, string $error = ''): void
    {
        $this->reply($unit->replyTo, $pid, $error);
        $unit->replyTo = null;
    }

    private function reply(?string $id, int $pid, string $error = ''): void
    {
        if ($id === null) {
            return;
        }
        try {
            ComponentControl::reply($id, $pid, $error);
        } catch (\Throwable) {
            // The caller stops waiting on its own.
        }
    }

    /**
     * Takes in a process the application was not declared with: it runs once, under the
     * application, and the application lets it go when it ends.
     *
     * @param Unit|null $previous What is left of its last run here — a failure.
     */
    private function adopt(ControlRequest $request, ?Unit $previous): void
    {
        try {
            $component = Component::adopted($request->class, $request->args);
        } catch (\Throwable $e) {
            // A class this process cannot load — newer code than it runs — among them.
            $this->logger->error("Not started on request: {$e->getMessage()}");
            $this->reply($request->replyTo, 0, $e->getMessage());
            return;
        }
        if ($previous !== null) {
            $this->units = array_values(array_filter($this->units, static fn(Unit $u): bool => $u !== $previous));
        }
        $unit = new Unit($component, ($this->name)($component));
        $unit->startAt = 0.0;
        $unit->replyTo = $request->replyTo;
        $this->units[] = $unit;
        $this->logger->info("{$unit->name} started on request: not declared, it runs once — never restarted.");
    }

    /** A component taken in has ended: nothing of it is kept. */
    private function letGo(Unit $unit): void
    {
        $this->forget($unit);
        $this->units = array_values(array_filter($this->units, static fn(Unit $u): bool => $u !== $unit));
    }

    /** @param class-string $class */
    private function unitOf(string $class): ?Unit
    {
        foreach ($this->units as $unit) {
            if ($unit->component->class === $class && $unit->component->kind !== ComponentKind::Command) {
                return $unit;
            }
        }
        return null;
    }

    /** A component that is down runs again — afresh: its count of restarts starts over. */
    private function startOnRequest(Unit $unit, string $what): void
    {
        $down = [UnitState::Stopped, UnitState::Completed, UnitState::Failed];
        if (!in_array($unit->state, $down, true)) {
            return;
        }
        $unit->restarts = [];
        $unit->state = UnitState::Waiting;
        $unit->startAt = 0.0;
        $this->logger->info("{$unit->name} {$what}.");
    }

    private function restartOnRequest(Unit $unit): void
    {
        if ($unit->state === UnitState::Running || $unit->state === UnitState::Starting) {
            // Stopped as on any request; it is started again as soon as it is down.
            $unit->restartPending = true;
            @posix_kill($unit->pid, SIGTERM);
            return;
        }
        if ($unit->state === UnitState::Waiting) {
            $unit->startAt = 0.0;
            return;
        }
        $this->startOnRequest($unit, 'restarted on request');
    }

    /** A process outside the application runs the component: wait for it, do not fight it. */
    private function hold(Unit $unit, int $outsider): void
    {
        $unit->reason = $outsider > 0
            ? "runs outside the application [PID {$outsider}]"
            : 'its start condition refuses';
        $unit->state = UnitState::Held;
        $unit->heldBy = $outsider;
        $unit->heldCheckedAt = microtime(true);
        // Tried again every second while held — by a process outside the application, or
        // by a start condition of its own (ensureNotRunning()): said once per wait.
        if ($unit->heldTold) {
            return;
        }
        $unit->heldTold = true;
        $this->logger->warning($outsider > 0
            ? "{$unit->name} runs outside the application [PID {$outsider}]; it starts here once that one ends."
            : "{$unit->name} cannot start yet: its start condition (ensureNotRunning()) refuses; trying every second.");
    }

    private function watchHeld(float $now): void
    {
        foreach ($this->units as $unit) {
            if ($unit->state !== UnitState::Held || $now - $unit->heldCheckedAt < self::HELD_INTERVAL) {
                continue;
            }
            $unit->heldCheckedAt = $now;
            if ($this->records->outsider($unit->component) === null) {
                if ($unit->heldBy > 0) {
                    $this->logger->info("{$unit->name} is free again: starting it.");
                }
                $unit->state = UnitState::Waiting;
                $unit->startAt = 0.0;
                $unit->heldBy = 0;
            }
        }
    }

    private function forget(Unit $unit): void
    {
        try {
            $this->records->forget($unit->component);
        } catch (\Throwable) {
            // A record left behind reads as down only while this run lasts.
        }
    }

    private function recordStopped(Unit $unit): void
    {
        try {
            $this->records->stopped($unit->component);
        } catch (\Throwable) {
            // Only the view is missing; the component stays down either way.
        }
    }

    /** The application ends: what the supervisor recorded of its components goes with it. */
    private function forgetAll(): void
    {
        foreach ($this->units as $unit) {
            if ($unit->pid === 0) {
                try {
                    $this->records->forget($unit->component);
                } catch (\Throwable) {
                    // A record left behind reads as down: its run is over.
                }
            }
        }
    }

    // ── Bookkeeping ───────────────────────────────────────────────────────────

    /**
     * Where every component stands, as `call application status` shows it.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        $now = microtime(true);
        $units = [];
        foreach ($this->units as $unit) {
            if ($unit->sinceState !== $unit->state) {
                $unit->sinceState = $unit->state;
                $unit->since = time();
            }
            $units[] = [
                'name'     => $unit->name,
                'kind'     => $unit->component->kind->name,
                'class'    => $unit->component->class,
                'state'    => $unit->state->name,
                'pid'      => $unit->pid,
                'since'    => $unit->since,
                'restarts' => count(array_filter(
                    $unit->restarts,
                    static fn(float $at): bool => $now - $at < Component::RETRY_WINDOW,
                )),
                'retries'  => $unit->component->retries,
                'critical' => $unit->component->critical,
                'retryAt'  => $unit->state === UnitState::Waiting && $unit->startAt > $now ? (int) $unit->startAt : 0,
                'reason'   => $unit->reason,
                'adopted'  => $unit->component->adopted,
            ];
        }
        return $units;
    }

    private function publish(): void
    {
        if ($this->publish === null) {
            return;
        }
        $snapshot = $this->snapshot();
        $key = json_encode(array_map(static function (array $u): array {
            unset($u['since']);
            return $u;
        }, $snapshot));
        // Said again every few seconds even when nothing changed: from another environment,
        // where its PID cannot be checked, that is how the application is known to be up.
        $now = microtime(true);
        if ($key === $this->published && $now - $this->publishedAt < self::HEARTBEAT) {
            return;
        }
        $this->published = (string) $key;
        $this->publishedAt = $now;
        try {
            ($this->publish)($snapshot);
        } catch (\Throwable) {
            // Only the view is missing; supervision goes on.
        }
    }

    private function alive(): int
    {
        $alive = 0;
        foreach ($this->units as $unit) {
            if ($unit->pid > 0) {
                $alive++;
            }
        }
        return $alive;
    }

    /**
     * Nothing more will run: every declared component finished its work, and none taken
     * in is still on its way — about to start, or waiting for an outside holder.
     */
    private function done(): bool
    {
        $pending = [UnitState::Waiting, UnitState::Starting, UnitState::Running, UnitState::Held];
        foreach ($this->units as $unit) {
            $busy = $unit->component->adopted
                ? in_array($unit->state, $pending, true)
                : $unit->state !== UnitState::Completed;
            if ($busy) {
                return false;
            }
        }
        return true;
    }

    private static function describe(int $status, bool $clean): string
    {
        if ($clean) {
            return 'ended without being asked to';
        }
        if (pcntl_wifsignaled($status)) {
            return 'killed by signal ' . pcntl_wtermsig($status);
        }
        return 'exit code ' . pcntl_wexitstatus($status);
    }
}
