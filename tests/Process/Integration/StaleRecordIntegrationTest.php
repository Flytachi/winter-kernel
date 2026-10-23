<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Process\Daemon\DaemonStatus;
use Flytachi\Winter\Kernel\Process\ProcessState;
use Flytachi\Winter\Kernel\Process\ProcessStatus;
use Flytachi\Winter\Kernel\Process\Stereotype\Process;
use Flytachi\Winter\Kernel\Process\Activity;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ExecChildProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\LoopDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use Flytachi\Winter\Kernel\Tests\Schedule\Fixtures\MarkerScheduler;
use Flytachi\Winter\Kernel\Tests\WebSocket\Fixtures\EchoSocket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A status record outlives a process killed without cleanup (SIGKILL, a host reboot,
 * `docker kill`), and after a restart its PID may be alive again — in a container,
 * usually as the very process that is starting. Liveness is decided by the singleton
 * lock, which the OS releases with the dead process, so such a record must neither
 * block the next start nor point stop() at a stranger.
 *
 * Every stereotype goes through the same start: a plain process, a daemon, the
 * scheduler and a WebSocket server.
 */
#[Group('integration')]
final class StaleRecordIntegrationTest extends IntegrationCase
{
    /** @var list<int> Unrelated programs started by a test, killed in tearDown(). */
    private array $strangers = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('WK_MARKER=' . $this->storage . '/marker');

        // Create every record directory up front: the test polls status() while the
        // child writes its first record, and FileStorage cannot take two processes
        // creating the same directory at once.
        foreach ([...array_column(self::stereotypes(), 0), ExecChildProcess::class] as $class) {
            self::store($class);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->strangers as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        putenv('WK_MARKER');
        parent::tearDown();
    }

    /** @return array<string, array{class-string<Process>}> */
    public static function stereotypes(): array
    {
        return [
            'process'   => [SignalProcess::class],
            'daemon'    => [LoopDaemon::class],
            'scheduler' => [MarkerScheduler::class],
            'websocket' => [EchoSocket::class],
        ];
    }

    // ── The reported case ─────────────────────────────────────────────────────

    /** @param class-string<Process> $class */
    #[DataProvider('stereotypes')]
    public function test_a_record_with_the_starting_process_own_pid_does_not_block_the_start(string $class): void
    {
        // A container restarted after a SIGKILL: the record says PID n, and the new
        // process is PID n again. The lock file is still there; nobody holds it.
        $this->touchLockFile($class);
        $starter = $this->starter($class);

        $pid = $this->fork(function () use ($class, $starter): void {
            $this->writeRecord($class, getmypid());
            $starter();
        });

        self::assertTrue(
            $this->pollUntil(fn() => $this->freshlyStarted($class, $pid)),
            "{$class} starts over a stale record carrying its own PID"
        );
    }

    public function test_own_pid_does_not_block_the_start_without_a_lock_file_either(): void
    {
        // No lock file to ask, so status() falls back to the PID — which is alive: it
        // is the caller's own. The caller is the one starting, so it cannot be running.
        $pid = $this->fork(function (): void {
            $this->writeRecord(SignalProcess::class, getmypid());
            SignalProcess::start();
        });

        self::assertTrue($this->pollUntil(fn() => $this->freshlyStarted(SignalProcess::class, $pid)));
    }

    /** @param class-string<Process> $class */
    #[DataProvider('stereotypes')]
    public function test_an_instance_killed_without_cleanup_starts_again(string $class): void
    {
        $starter = $this->starter($class);
        $first = $this->fork($starter);
        self::assertTrue($this->pollUntil(fn() => $class::status()?->pid === $first), 'the first instance runs');

        $this->killHard($class, $first);

        self::assertNotNull($this->readRecord($class), 'SIGKILL leaves the record behind');
        self::assertNull($class::status(), 'a record whose lock is free is not a running instance');

        $second = $this->fork($starter);
        self::assertTrue(
            $this->pollUntil(fn() => $this->freshlyStarted($class, $second)),
            "{$class} starts again after its predecessor was killed"
        );
    }

    // ── stop() and status() ───────────────────────────────────────────────────

    public function test_stop_does_not_signal_a_stranger_that_got_the_stale_pid(): void
    {
        $stranger = $this->stranger();
        $this->touchLockFile(SignalProcess::class);
        $this->writeRecord(SignalProcess::class, $stranger);

        self::assertNull(SignalProcess::status(), 'the stranger is not taken for the process');
        self::assertFalse(SignalProcess::stop(), 'nothing to stop');
        usleep(100_000);
        self::assertTrue($this->isAlive($stranger), 'the stranger got no SIGTERM');
    }

    public function test_a_running_instance_is_still_seen_and_refuses_a_second_start(): void
    {
        $first = $this->fork(static fn() => SignalProcess::start());
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->pid === $first));

        $second = $this->fork(static fn() => SignalProcess::start());

        self::assertTrue($this->waitExit($second, 4.0), 'the second instance bails out');
        self::assertSame($first, SignalProcess::status()?->pid, 'the first one is still reported');
        self::assertTrue(SignalProcess::stop(), 'and can be stopped');
        self::assertTrue($this->waitExit($first), 'by its own PID');
    }

    public function test_a_running_instance_records_that_its_lock_can_be_trusted(): void
    {
        $pid = $this->fork(static fn() => SignalProcess::start());
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->pid === $pid));

        self::assertTrue($this->readRecord(SignalProcess::class)->locked, 'flock works on a local filesystem');
    }

    public function test_without_a_lock_file_the_pid_decides_as_before(): void
    {
        $stranger = $this->stranger();
        $this->writeRecord(SignalProcess::class, $stranger);
        self::assertSame($stranger, SignalProcess::status()?->pid, 'a live PID counts when there is no lock to ask');

        posix_kill($stranger, SIGKILL);
        $this->pollUntil(fn() => pcntl_waitpid($stranger, $s, WNOHANG) !== 0);
        self::assertNull(SignalProcess::status(), 'a dead PID does not');
    }

    public function test_where_flock_does_not_work_the_pid_decides_as_before(): void
    {
        // Docker Desktop's host mounts grant every flock: a free lock proves nothing
        // there, and a record that says so is judged by its PID.
        $stranger = $this->stranger();
        $this->touchLockFile(SignalProcess::class);
        $this->writeRecord(SignalProcess::class, $stranger, locked: false);

        self::assertSame($stranger, SignalProcess::status()?->pid, 'a live PID counts');
    }

    public function test_a_record_from_before_the_lock_flag_is_judged_by_its_pid(): void
    {
        $stranger = $this->stranger();
        $this->touchLockFile(SignalProcess::class);
        // What an older kernel wrote: no `locked` in the serialized record.
        $legacy = serialize(
            new ProcessStatus($stranger, SignalProcess::class, ProcessState::RUNNING, Activity::IDLE, time())
        );
        $legacy = str_replace(':9:{', ':8:{', preg_replace('/s:6:"locked";b:0;/', '', $legacy));
        self::store(SignalProcess::class)->write(self::key(SignalProcess::class), unserialize($legacy));

        self::assertFalse($this->readRecord(SignalProcess::class)->locked, 'the missing field reads as false');
        self::assertSame($stranger, SignalProcess::status()?->pid, 'and the PID decides');
    }

    // ── The lock itself ───────────────────────────────────────────────────────

    public function test_a_program_the_killed_process_executed_does_not_keep_its_lock(): void
    {
        $first = $this->fork(static fn() => ExecChildProcess::start());
        self::assertTrue($this->pollUntil(fn() => $this->execedPid() > 0 && ExecChildProcess::status() !== null));
        $program = $this->execedPid();
        $this->strangers[] = $program;

        $this->killHard(ExecChildProcess::class, $first);

        self::assertTrue($this->isAlive($program), 'the executed program outlives the process');
        self::assertNull(ExecChildProcess::status(), 'it does not hold the dead process\'s lock');

        $second = $this->fork(static fn() => ExecChildProcess::start());
        self::assertTrue($this->pollUntil(fn() => $this->freshlyStarted(ExecChildProcess::class, $second)));
        $this->strangers[] = $this->execedPid();
    }

    public function test_a_status_check_at_the_moment_of_start_does_not_turn_the_start_away(): void
    {
        // status() takes a shared lock for an instant; hold one far longer than that.
        $this->touchLockFile(SignalProcess::class);
        $probe = fopen($this->lockFile(SignalProcess::class), 'r');
        flock($probe, LOCK_SH);

        $pid = $this->fork(static fn() => SignalProcess::start());
        usleep(30_000);
        flock($probe, LOCK_UN);
        fclose($probe);

        self::assertTrue($this->pollUntil(fn() => SignalProcess::status()?->pid === $pid), 'the start waits it out');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param class-string<Process> $class */
    private function starter(string $class): \Closure
    {
        // Process, Daemon and Scheduler run with Swoole and without it; a WebSocket
        // server is Swoole's own and refuses to start without it.
        if ($class === EchoSocket::class && !extension_loaded('swoole')) {
            self::markTestSkipped('a WebSocket server needs ext-swoole.');
        }
        if ($class === EchoSocket::class) {
            $port = self::freePort();
            return static fn() => EchoSocket::start($port, '127.0.0.1');
        }
        return static fn() => $class::start();
    }

    /** Whether the record is the one the given instance wrote while running. */
    private function freshlyStarted(string $class, int $pid): bool
    {
        $status = $class::status();
        // The stale records are an hour old, and may carry the same PID.
        if ($status === null || $status->pid !== $pid || $status->startedAt < time() - 60) {
            return false;
        }
        // A daemon is up once its fleet is; the others once the record is theirs.
        return !$status instanceof DaemonStatus || count($status->workers) > 0;
    }

    /** SIGKILL — no finally, no shutdown function: exactly what `docker kill` does. */
    private function killHard(string $class, int $pid): void
    {
        $status = $class::status();
        $workers = $status instanceof DaemonStatus ? array_map(fn($w) => $w->pid, $status->workers) : [];
        posix_kill($pid, SIGKILL);
        foreach ($workers as $worker) {
            if ($worker > 0) {
                @posix_kill($worker, SIGKILL);
            }
        }
        self::assertTrue($this->waitExit($pid), 'the instance is dead');
        $this->pollUntil(fn() => array_filter($workers, fn($w) => $this->isAlive($w)) === []);
    }

    /** A live process unrelated to the test subject. */
    private function stranger(): int
    {
        $pid = $this->fork(static function (): void {
            sleep(30);
        });
        return $this->strangers[] = $pid;
    }

    private function execedPid(): int
    {
        $lines = @file($this->storage . '/marker', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return (int) end($lines);
    }

    /** @param class-string<Process> $class */
    /**
     * A record as a dead instance left it. $locked as the instance found its lock: true
     * on a filesystem where `flock` works, which is what a running instance writes here.
     */
    private function writeRecord(string $class, int $pid, bool $locked = true): void
    {
        $status = new ProcessStatus(
            pid: $pid,
            className: $class,
            state: ProcessState::RUNNING,
            activity: Activity::IDLE,
            startedAt: time() - 3600,
        );
        $status->locked = $locked;
        self::store($class)->write(self::key($class), $status);
    }

    /** The record as stored, whether or not anyone is alive behind it. */
    private function readRecord(string $class): mixed
    {
        return self::store($class)->read(self::key($class));
    }

    private function touchLockFile(string $class): void
    {
        touch($this->lockFile($class));
    }

    private function lockFile(string $class): string
    {
        return $this->storage . '/runnable/' . str_replace('\\', '.', $class) . '.lock';
    }

    private static function store(string $class): \Flytachi\FileStore\FileStorage
    {
        return (new \ReflectionMethod($class, 'store'))->invoke(null);
    }

    private static function key(string $class): string
    {
        return (new \ReflectionMethod($class, 'key'))->invoke(null);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }
}
