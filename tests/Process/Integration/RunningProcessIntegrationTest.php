<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ProbeDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ProbeProcess;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ProbeWorkerClassDaemon;
use PHPUnit\Framework\Attributes\Group;

/**
 * `Process::current()` must already answer while the process is being built.
 *
 * A config sizes its pool in `setUp()`, and `setUp()` runs when the first repository is
 * constructed — through `#[Autowired]`, that is before the body starts. The probes record
 * what the repository and the config saw, and the pool ceiling the body ended up with.
 */
#[Group('integration')]
final class RunningProcessIntegrationTest extends IntegrationCase
{
    private string $marker;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('the pool is exercised against SQLite.');
        }
        parent::setUp();
        $this->marker = $this->storage . '/marker';
        putenv('WK_MARKER=' . $this->marker);
        putenv('WK_DB=' . $this->storage . '/probe.sqlite');
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        putenv('WK_DB');
        parent::tearDown();
    }

    /** @return list<string> */
    private function marks(): array
    {
        return is_file($this->marker)
            ? array_values(array_filter(explode("\n", (string) file_get_contents($this->marker))))
            : [];
    }

    private function waitForMark(string $mark): void
    {
        self::assertTrue(
            $this->pollUntil(fn() => in_array($mark, $this->marks(), true)),
            "expected «{$mark}», got:\n" . implode("\n", $this->marks()),
        );
    }

    /**
     * Waits for the body's pool mark. The pool is Swoole's: without it there is no
     * ceiling to read, but the body still ran, and what the process knew while its
     * repositories were built — the point of these tests — holds all the same.
     */
    private function waitForPoolMark(string $who): void
    {
        if (extension_loaded('swoole')) {
            $this->waitForMark("{$who} maximum=3");
            return;
        }
        self::assertTrue(
            $this->pollUntil(fn() => preg_grep("/^{$who} maximum=/", $this->marks()) !== []),
            "expected the {$who} body to run, got:\n" . implode("\n", $this->marks()),
        );
    }

    public function test_a_process_is_current_while_its_repositories_are_built(): void
    {
        $pid = $this->fork(static fn() => ProbeProcess::start());

        $this->waitForPoolMark('process');
        self::assertContains('built current=ProbeProcess supervising=null', $this->marks());
        self::assertContains('setUp current=ProbeProcess slot=null supervising=null', $this->marks());

        posix_kill($pid, SIGTERM);
        self::assertTrue($this->waitExit($pid));
    }

    public function test_the_supervisor_is_recognised_and_its_worker_carries_the_slot(): void
    {
        $sup = $this->fork(static fn() => ProbeDaemon::start());

        $this->waitForPoolMark('worker');
        self::assertContains(
            'built current=null supervising=ProbeDaemon',
            $this->marks(),
            'the daemon instance is built in the supervisor: not a process, but its supervisor',
        );
        self::assertContains('setUp current=ProbeDaemon slot=0 supervising=null', $this->marks());

        posix_kill($sup, SIGTERM);
        self::assertTrue($this->waitExit($sup));
    }

    public function test_an_external_worker_is_current_while_it_is_built(): void
    {
        $sup = $this->fork(static fn() => ProbeWorkerClassDaemon::start());

        $this->waitForPoolMark('worker');
        self::assertContains('built current=ProbeWorkerClassDaemon supervising=null', $this->marks());
        self::assertNotContains('setUp current=null slot=null supervising=null', $this->marks());

        posix_kill($sup, SIGTERM);
        self::assertTrue($this->waitExit($sup));
    }
}
