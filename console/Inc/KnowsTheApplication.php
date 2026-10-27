<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Inc;

use Flytachi\Winter\Kernel\App\Runtime\ApplicationRecord;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\Process\ProcessAlreadyRunningException;
use Flytachi\Winter\Kernel\Process\ProcessManagedException;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Kernel\Process\Stereotype\Process as ProcessUnit;
use Flytachi\Winter\Kernel\WinterApplication;

/**
 * What `call process` / `call daemon` / `call schedule` need to know about the running
 * application: whether it runs here, elsewhere or not at all, and whose a process is —
 * one it was declared with (boot), one it took in (adopted), or nobody's.
 *
 * While it runs here, the application starts every process; in another environment,
 * nothing is started here.
 *
 * @mixin Cmd
 */
trait KnowsTheApplication
{
    /** @var array{where: string, record: ?ApplicationRecord, units: array<string, array<string, mixed>>}|null */
    private ?array $applicationView = null;

    /**
     * @return array{where: string, record: ?ApplicationRecord, units: array<string, array<string, mixed>>}
     *     `where` is `here`, `elsewhere` or `none`; units are keyed by class.
     */
    private function application(): array
    {
        if ($this->applicationView !== null) {
            return $this->applicationView;
        }
        $record = ApplicationRuntime::serving();
        $where = 'here';
        if ($record === null) {
            $record = ApplicationRuntime::servingElsewhere();
            $where = $record !== null ? 'elsewhere' : 'none';
        }
        $units = [];
        if ($record !== null) {
            foreach (ApplicationRuntime::units(WinterApplication::getAppClass()) as $unit) {
                if (is_string($unit['class'] ?? null)) {
                    $units[$unit['class']] = $unit;
                }
            }
        }
        return $this->applicationView = ['where' => $where, 'record' => $record, 'units' => $units];
    }

    /**
     * Whose the class is while the application runs: `boot` (declared), `adopted` (taken
     * in on request), or null.
     *
     * @param class-string $class
     */
    private function roleOf(string $class): ?string
    {
        $app = $this->application();
        if ($app['record'] === null) {
            return null;
        }
        if (($app['units'][$class]['adopted'] ?? false) === true) {
            return 'adopted';
        }
        foreach ($app['record']->components as $component) {
            if (($component['class'] ?? null) === $class) {
                return 'boot';
            }
        }
        return null;
    }

    /** A list's label, saying when the application runs — in its own colour. */
    private function printListLabel(string $label, int $cl): void
    {
        $app = $this->application();
        $tag = match ($app['where']) {
            'here' => " (\033[32mapplication\033[{$cl}m)",
            'elsewhere' => " (\033[33mapplication · {$app['record']?->host}\033[{$cl}m)",
            default => '',
        };
        echo "\033[{$cl}m | [ {$label}{$tag} ]\n\033[0m";
    }

    /** A row's tag: whose the process is. */
    private function roleTag(string $class): string
    {
        return match ($this->roleOf($class)) {
            'boot' => "\033[36m [boot]",
            'adopted' => "\033[95m [adopted]",
            default => '',
        };
    }

    /**
     * A record the supervisor wrote for a component that is down — no process of its own;
     * the PID in it is the application's.
     */
    private function isDownRecord(ProcessStatus $info): bool
    {
        return $info->managedBy !== null
            && ($info->state === ProcessState::TERMINATED || $info->state === ProcessState::FAILED);
    }

    /** A list row for a component that is down: its state, whose it is, for how long. */
    private function downTag(string $class, ProcessStatus $info): string
    {
        return ($info->state === ProcessState::FAILED ? "\033[31m[✕ FAILED]" : "\033[31m[○ STOPPED]")
            . $this->roleTag($class)
            . "\033[90m " . $this->formatDuration(time() - $info->startedAt);
    }

    /** `status` of a component that is down: when, for how long, and whose it is. */
    private function printDownStatus(string $dot, ProcessStatus $info, string $kind, int $cl): void
    {
        $failed = $info->state === ProcessState::FAILED;
        self::printBadge($dot, $failed ? '✕ FAILED' : '○ STOPPED', $cl, 31);
        self::printDivider();
        self::printKeyValue("PID", '—', 12, $cl, 90);
        self::printKeyValue("State", $failed ? 'FAILED — could not run' : 'STOPPED — on request', 12, $cl, 36);
        self::printKeyValue("Managed by", "application [PID {$info->managedBy?->pid}]", 12, $cl, 32);
        self::printKeyValue($failed ? "Failed at" : "Stopped at", $info->getStartedAt(), 12, $cl, 36);
        self::printKeyValue("Down for", $this->formatDuration(time() - $info->startedAt), 12, $cl, 36);
        self::printInfo($failed
            ? "Why: `call application status`. Start it again: `call {$kind} {$dot} start`."
            : "Start it again: `call {$kind} {$dot} start`.");
    }

    /** `status` lines about whose a running process is. */
    private function printOwnership(string $class, ProcessStatus $info, int $cl): void
    {
        if ($info->host !== null && $info->host !== ApplicationRuntime::host()) {
            self::printKeyValue("Host", "{$info->host} — another environment", 12, $cl, 33);
        }
        $role = $this->roleOf($class);
        if ($role !== null) {
            self::printKeyValue(
                "Managed by",
                "application [PID {$this->application()['record']?->pid}] — "
                    . ($role === 'boot' ? 'boot component' : 'adopted: runs once'),
                12,
                $cl,
                32
            );
        }
    }

    /**
     * Starts the class through the running application, or refuses while it runs in
     * another environment.
     *
     * @param class-string<ProcessUnit> $class
     * @param \Closure(): int $dispatch Starts it — the class's dispatch() with its arguments.
     * @return bool Whether the application had its say; false when it does not run.
     */
    private function startThroughApplication(string $class, string $kind, \Closure $dispatch, int $cl): bool
    {
        $app = $this->application();
        if ($app['where'] === 'none') {
            return false;
        }
        $dot = str_replace('\\', '.', $class);
        if ($app['where'] === 'elsewhere') {
            self::printWarning("The application runs in another environment ({$app['record']?->host}): start it there.");
            self::printInfo("For a container: docker compose exec <service> php call {$kind} {$dot} start");
            return true;
        }

        try {
            $pid = $dispatch();
        } catch (ProcessAlreadyRunningException | ProcessManagedException $e) {
            self::printWarning($e->getMessage());
            return true;
        }
        $declared = $this->roleOf($class) === 'boot';
        self::printSuccess("Started by the application: {$class}");
        self::printKeyValue("PID", $pid > 0 ? (string) $pid : 'starting …', 12, $cl, 32);
        self::printKeyValue(
            "Runs as",
            $declared ? 'boot component — with its declared settings' : 'adopted — runs once: no restarts, not critical',
            12,
            $cl,
            36
        );
        self::printInfo("Follow it: call application status");
        return true;
    }

    /**
     * Stops and starts the class again — through the application while it runs.
     *
     * @param class-string<ProcessUnit> $class
     */
    private function restartThroughApplication(string $class, string $kind, int $cl): void
    {
        $app = $this->application();
        $dot = str_replace('\\', '.', $class);
        if ($app['where'] === 'elsewhere') {
            self::printWarning("The application runs in another environment ({$app['record']?->host}): restart it there.");
            self::printInfo("For a container: docker compose exec <service> php call {$kind} {$dot} restart");
            return;
        }
        if (!$class::relaunch()) {
            self::printWarning("Not restarted: it runs in another environment, or did not stop within a minute.");
            return;
        }
        if ($app['where'] === 'here') {
            self::printSuccess("Restart requested: the application restarts {$class}.");
            self::printInfo("Follow it: call application status");
            return;
        }
        $info = null;
        for ($i = 0; $i < 20 && $info === null; $i++) {
            usleep(50_000);
            $info = $class::status();
        }
        self::printSuccess("Restarted (background): {$class}");
        self::printKeyValue("PID", $info !== null ? (string) $info->pid : 'starting …', 12, $cl, 32);
    }
}
