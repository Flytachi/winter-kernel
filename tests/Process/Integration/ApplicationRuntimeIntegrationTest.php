<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\ApplicationConfigException;
use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRecord;
use Flytachi\Winter\Kernel\App\Runtime\ApplicationRuntime;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\SignalProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * The application's claim on its run: one `call run` per application and storage, a
 * record that outlives a master killed without cleanup but is not taken for a live one,
 * and components that name the run that started them.
 */
#[Group('integration')]
final class ApplicationRuntimeIntegrationTest extends IntegrationCase
{
    private const string APP = 'Tests\\RuntimeProbeApp';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('WK_MARKER=' . $this->storage . '/marker');
        // The record directory exists before any child races to create it.
        ApplicationRuntime::record(self::APP);
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        parent::tearDown();
    }

    public function test_a_second_run_of_the_same_application_is_refused(): void
    {
        $master = $this->runMaster();

        try {
            ApplicationRuntime::claim(self::APP, []);
            self::fail('a second claim must be refused');
        } catch (ApplicationConfigException $e) {
            self::assertStringContainsString("already running [PID {$master}]", $e->getMessage());
        }
    }

    public function test_the_first_run_on_an_empty_storage_holds_its_lock_too(): void
    {
        // No runnable directory yet: the lock file must not be tried before it exists, or
        // the first master runs without a lock and the second is not refused.
        rmdir($this->storage . '/runnable/app');
        rmdir($this->storage . '/runnable');
        (new \ReflectionProperty(\Flytachi\Winter\Kernel\Core\KernelStore::class, 'runnable'))->setValue(null, []);

        // Nothing here may touch the store until the master has claimed: reading it would
        // create the directory first and hide the case.
        $claimed = $this->storage . '/claimed';
        $master = $this->fork(static function () use ($claimed): void {
            ApplicationRuntime::claim(self::APP, []);
            touch($claimed);
            sleep(30);
        });
        self::assertTrue($this->pollUntil(static fn() => is_file($claimed)));

        self::assertTrue(ApplicationRuntime::live(self::APP)?->locked, 'the first master holds a working lock');
        $this->expectExceptionMessage("already running [PID {$master}]");
        ApplicationRuntime::claim(self::APP, []);
    }

    public function test_a_master_killed_without_cleanup_does_not_block_the_next_run(): void
    {
        $master = $this->runMaster();
        posix_kill($master, SIGKILL);
        self::assertTrue($this->waitExit($master));

        $stale = ApplicationRuntime::record(self::APP);
        self::assertNotNull($stale, 'SIGKILL leaves the record behind');
        self::assertFalse(ApplicationRuntime::isAlive($stale), 'its lock is free');
        self::assertNull(ApplicationRuntime::live(self::APP));

        $next = $this->fork(function (): void {
            ApplicationRuntime::claim(self::APP, []);
            sleep(30);
        });
        self::assertTrue($this->pollUntil(fn() => ApplicationRuntime::live(self::APP)?->pid === $next));
    }

    public function test_a_live_master_is_seen_with_what_it_runs(): void
    {
        $master = $this->runMaster([Component::http(port: 8123), Component::process(SignalProcess::class)]);

        $live = ApplicationRuntime::live(self::APP);
        self::assertSame($master, $live?->pid);
        self::assertSame(ApplicationRuntime::host(), $live->host);
        self::assertTrue($live->locked, 'flock works on a local filesystem');
        self::assertSame(
            [
                ['kind' => 'Http', 'class' => null, 'port' => 8123],
                ['kind' => 'Process', 'class' => SignalProcess::class, 'port' => null],
            ],
            $live->components,
        );
    }

    public function test_a_record_from_another_host_is_neither_confirmed_nor_denied(): void
    {
        $foreign = new ApplicationRecord(getmypid(), self::APP, time(), 'another-container', true, []);

        self::assertNull(ApplicationRuntime::isAlive($foreign), 'its PID belongs to another namespace');
    }

    public function test_where_flock_does_not_work_the_previous_master_is_judged_by_its_pid(): void
    {
        // Docker Desktop mounts grant every flock, so a free lock proves nothing there and
        // claim() asks this instead. That filesystem cannot be reproduced here; the rule is.
        $runsElsewhere = new \ReflectionMethod(ApplicationRuntime::class, 'runsElsewhere');
        $host = ApplicationRuntime::host();

        self::assertTrue(
            $runsElsewhere->invoke(null, new ApplicationRecord(posix_getppid(), self::APP, time(), $host, false, [])),
            'a live master on this host — the run is refused',
        );
        self::assertFalse(
            $runsElsewhere->invoke(null, new ApplicationRecord(getmypid(), self::APP, time(), $host, false, [])),
            'its own PID — a container restarted after a SIGKILL',
        );
        self::assertFalse(
            $runsElsewhere->invoke(
                null,
                new ApplicationRecord(posix_getppid(), self::APP, time(), 'another-container', false, []),
            ),
            'another host — its PID means nothing here',
        );
    }

    public function test_a_component_started_by_the_application_names_its_run(): void
    {
        $this->fork(static function (): void {
            $app = ApplicationRuntime::claim(self::APP, []);
            ApplicationRuntime::markManaged(SignalProcess::class);
            file_put_contents(dirname((string) getenv('WK_MARKER')) . '/app.json', json_encode($app->managedBy()));
            SignalProcess::start();
        });
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status() !== null));

        $status = SignalProcess::status();
        self::assertSame(ApplicationRuntime::host(), $status->host);
        self::assertNotNull($status->managedBy);
        self::assertSame(
            json_decode((string) file_get_contents($this->storage . '/app.json'), true),
            $status->managedBy->jsonSerialize(),
        );
        self::assertTrue($status->managedBy->isRun(ApplicationRuntime::live(self::APP)));
    }

    public function test_a_process_started_on_its_own_names_no_application(): void
    {
        $this->fork(static fn() => SignalProcess::start());
        self::assertTrue($this->pollUntil(fn() => SignalProcess::status() !== null));

        self::assertNull(SignalProcess::status()->managedBy);
        self::assertSame(ApplicationRuntime::host(), SignalProcess::status()->host);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * A master holding the claim, as `call run` does.
     *
     * @param list<Component> $components
     */
    private function runMaster(array $components = []): int
    {
        $pid = $this->fork(static function () use ($components): void {
            ApplicationRuntime::claim(self::APP, $components);
            sleep(30);
        });
        self::assertTrue($this->pollUntil(fn() => ApplicationRuntime::live(self::APP)?->pid === $pid));
        return $pid;
    }
}
