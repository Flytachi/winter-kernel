<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App\Supervision;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Process\BeforeFork;
use Flytachi\Winter\Kernel\Process\Internal\Termination;
use Flytachi\Winter\Kernel\Process\ProcessAlreadyRunningException;
use Psr\Log\LoggerInterface;

/**
 * Runs an application's background components and keeps them running — the same in
 * both shapes of `call run`: inside the master when there is no web tier, inside one
 * process of the Swoole server when there is.
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
 * being asked to has crashed.
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

    /** @var list<Unit> */
    private array $units = [];
    private bool $stopping = false;
    private int $exitCode = 0;
    private float $commandsAt = 0.0;
    /** A component asked for the whole application to restart in place (`call run dev`). */
    private bool $reloadRequested = false;

    /**
     * @param list<Component> $components
     * @param \Closure(Component): void $launch Runs a component in the current process.
     * @param \Closure(string, \Throwable): void $report Says, loudly, why a component threw.
     * @param \Closure(string): void $announce Says one line, loudly — a critical failure.
     * @param ComponentRecords $records The records of components that are down.
     * @param \Closure(Component): string $name How logs name a component.
     * @param (\Closure(Component): ?string)|null $commands The pending request for a
     *     component ({@see ComponentControl::START} or {@see ComponentControl::RESTART}),
     *     or null; none are taken when absent.
     */
    public function __construct(
        array $components,
        private readonly \Closure $launch,
        private readonly \Closure $report,
        private readonly \Closure $announce,
        private readonly ComponentRecords $records,
        \Closure $name,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $commands = null,
    ) {
        foreach ($components as $component) {
            $this->units[] = new Unit($component, $name($component));
        }
    }

    /**
     * Runs until the application is stopped, or a critical component fails.
     *
     * @param bool $untilDone Also return once every component has finished its work
     *                        (headless): nothing is left to run. Under the web tier the
     *                        supervisor stays as long as the server does.
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

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop(SIGTERM));
        pcntl_signal(SIGINT, fn() => $this->stop(SIGINT));
        // What a Swoole server answers to — a graceful reload of its workers — reaches the
        // HTTP server, as it did when the server was this process.
        foreach ([SIGHUP, SIGUSR1, SIGUSR2] as $signal) {
            pcntl_signal($signal, fn() => $this->forward($signal, ComponentKind::Http));
        }

        while (true) {
            $now = microtime(true);
            if (!$this->stopping) {
                $this->takeCommands($now);
                $this->watchHeld($now);
                $this->startDue($now);
            }
            $this->listen();
            $this->reap();
            $this->enforceStartTimeouts(microtime(true));

            if ($this->alive() === 0 && ($this->stopping || ($untilDone && $this->done()))) {
                $this->forgetAll();
                return $this->exitCode;
            }
        }
    }

    /** Whether a component asked for the application to restart in place. */
    public function reloadRequested(): bool
    {
        return $this->reloadRequested;
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

    /** Stops every component: the application is shutting down. */
    private function stop(int $signal): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $now = microtime(true);
        foreach ($this->units as $unit) {
            if ($unit->pid > 0) {
                @posix_kill($unit->pid, $signal);
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
            $this->fail($unit, 'could not open its channel');
            return;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
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
        $unit->socket = $pair[0];
        $unit->state = UnitState::Starting;
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
        foreach ([SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
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
        foreach ([SIGTERM, SIGINT, SIGHUP, SIGUSR1, SIGUSR2] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
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
                $this->stop(SIGTERM);
            }
        }
    }

    private function ready(Unit $unit): void
    {
        if ($unit->state !== UnitState::Starting) {
            return;
        }
        $unit->state = UnitState::Running;
        $unit->everReady = true;
        $unit->readyDeadline = 0.0;
        $unit->heldTold = false;
        $this->logger->info("{$unit->name} is up.");
    }

    private function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
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
                $this->stop(SIGTERM);
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
            $unit->state = UnitState::Stopped;
            $this->logger->info("{$unit->name} stopped.");
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
            $unit->state = UnitState::Completed;
            $this->logger->info("{$unit->name} finished its work.");
            $this->recordStopped($unit);
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
        if ($unit->component->critical) {
            ($this->announce)("{$unit->name} failed: {$reason}. It is critical: the application stops.");
            $unit->state = UnitState::Failed;
            $this->exitCode = 1;
            $this->stop(SIGTERM);
            return;
        }
        $unit->state = UnitState::Failed;
        $this->logger->error(
            "{$unit->name} failed: {$reason}. It is not critical: the application runs on without it."
        );
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
        foreach ($this->units as $unit) {
            $action = ($this->commands)($unit->component);
            if ($action === ComponentControl::START) {
                $this->startOnRequest($unit, 'started on request');
            } elseif ($action === ComponentControl::RESTART) {
                $this->restartOnRequest($unit);
            }
        }
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

    /** Nothing more will run: every component finished its work. */
    private function done(): bool
    {
        foreach ($this->units as $unit) {
            if ($unit->state !== UnitState::Completed) {
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
