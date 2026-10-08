<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Process\Engine\SyncEngine;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ForkProbe;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ForkProbeDaemon;
use Flytachi\Winter\Kernel\Tests\Process\Fixtures\ForkTxnDaemon;
use Flytachi\Winter\Ppa\Pool\PpaConnectionPool;
use Flytachi\Winter\Ppa\Pool\PpaPoolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * A fork must not cost the parent its database session.
 *
 * A forked child gets a copy of every PDO object the parent held, socket included. When
 * the child ends — or forgets the copy in its fork reset — the PDO destructor tells the
 * server to close the session (Terminate on PostgreSQL, COM_QUIT on MySQL), over the
 * socket it shares with the parent. The parent's next query then fails with "server
 * closed the connection". That happened to every `spawn()` without Swoole, whether or not
 * the child touched the database, and to a daemon supervisor that had a connection open
 * when it forked a worker.
 *
 * Runs against live servers: PG_TEST_* and MARIADB_TEST_* (see tests/run-integration.sh).
 */
#[Group('integration')]
final class ForkConnectionIntegrationTest extends IntegrationCase
{
    private string $marker;

    /** @return iterable<string, array{string}> */
    public static function databases(): iterable
    {
        yield 'PostgreSQL' => ['pg'];
        yield 'MariaDB' => ['mariadb'];
    }

    private function useDatabase(string $db): void
    {
        if (!method_exists(PpaConnectionPool::class, 'closeBeforeFork')) {
            self::markTestSkipped('fork protection needs winter-ppa 1.2 or later.');
        }
        $dsn = getenv($db === 'pg' ? 'PG_TEST_DSN' : 'MARIADB_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped("no {$db} server configured for the integration tests.");
        }
        putenv('WK_FORK_DB=' . $db);
        if (str_starts_with(ForkProbe::session(), 'FAILED')) {
            self::markTestSkipped("the {$db} server is not reachable: " . ForkProbe::session());
        }
        PpaConnectionPool::reset();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = $this->storage . '/marker';
        putenv('WK_MARKER=' . $this->marker);
    }

    protected function tearDown(): void
    {
        putenv('WK_MARKER');
        putenv('WK_FORK_DB');
        parent::tearDown();
    }

    /** @return list<string> */
    private function marks(): array
    {
        return is_file($this->marker)
            ? array_values(array_filter(explode("\n", (string) file_get_contents($this->marker))))
            : [];
    }

    /** Runs $parent as the process under test and waits for its report. */
    private function runParent(callable $parent): void
    {
        $pid = $this->fork($parent);
        self::assertTrue($this->pollUntil(fn() => in_array('done', $this->marks(), true)), implode("\n", $this->marks()));
        $this->waitExit($pid, 2.0);
    }

    /** "session 81" → "81"; a failure stays as it is, so the assertion message shows it. */
    private static function idOf(string $mark, string $prefix): string
    {
        return substr($mark, strlen($prefix));
    }

    #[DataProvider('databases')]
    public function test_a_spawned_child_that_never_queries_leaves_the_parent_session_alone(string $db): void
    {
        $this->useDatabase($db);

        $this->runParent(static function (): void {
            ForkProbe::mark('before ' . ForkProbe::session());
            $engine = new SyncEngine(concurrency: 0, grace: 5.0);
            $engine->enter(static function () use ($engine): void {
                $engine->spawn(static function (): void {
                });
            });
            usleep(200_000);
            ForkProbe::mark('after ' . ForkProbe::session());
            ForkProbe::mark('done');
        });

        $after = $this->marks()[1];
        self::assertStringNotContainsString('FAILED', $after, 'the parent can still query after the child left');
    }

    #[DataProvider('databases')]
    public function test_a_spawned_child_gets_a_session_of_its_own(string $db): void
    {
        $this->useDatabase($db);

        $this->runParent(static function (): void {
            ForkProbe::mark('parent ' . ForkProbe::session());
            $engine = new SyncEngine(concurrency: 0, grace: 5.0);
            $engine->enter(static function () use ($engine): void {
                $engine->spawn(static function (): void {
                    ForkProbe::mark('child ' . ForkProbe::session());
                });
            });
            usleep(200_000);
            ForkProbe::mark('again ' . ForkProbe::session());
            ForkProbe::mark('done');
        });

        $marks = $this->marks();
        $child = current(array_filter($marks, static fn($m) => str_starts_with($m, 'child ')));
        $again = current(array_filter($marks, static fn($m) => str_starts_with($m, 'again ')));
        $parent = $marks[0];

        self::assertStringNotContainsString('FAILED', (string) $child, implode("\n", $marks));
        self::assertNotSame(
            self::idOf($parent, 'parent '),
            self::idOf((string) $child, 'child '),
            'the child must not share the parent\'s session',
        );
        self::assertStringNotContainsString('FAILED', (string) $again, 'the parent queries on after the child: ' . implode("\n", $marks));
    }

    #[DataProvider('databases')]
    public function test_spawning_inside_an_open_transaction_is_refused_and_the_transaction_survives(string $db): void
    {
        $this->useDatabase($db);

        $this->runParent(static function (): void {
            $cdo = PpaConnectionPool::db(ForkProbe::config());
            $cdo->beginTransaction();
            $engine = new SyncEngine(concurrency: 0, grace: 5.0);
            try {
                $engine->enter(static function () use ($engine): void {
                    $engine->spawn(static function (): void {
                    });
                });
                ForkProbe::mark('spawned');
            } catch (PpaPoolException $e) {
                ForkProbe::mark('refused ' . $e->getMessage());
            }
            ForkProbe::mark('in transaction ' . var_export($cdo->inTransaction(), true));
            $cdo->rollBack();
            ForkProbe::mark('done');
        });

        $marks = $this->marks();
        self::assertNotContains('spawned', $marks, 'a fork would have shared the transaction\'s connection');
        self::assertStringContainsString('open transaction', $marks[0]);
        self::assertContains('in transaction true', $marks, 'the refusal must not touch the transaction');
    }

    #[DataProvider('databases')]
    public function test_a_supervisor_keeps_its_session_while_it_forks_workers(string $db): void
    {
        $this->useDatabase($db);

        $sup = $this->fork(static fn() => ForkProbeDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => count(array_filter($this->marks(), static fn($m) => str_starts_with($m, 'supervisor '))) >= 4,
            10.0,
        ), implode("\n", $this->marks()));
        posix_kill($sup, SIGTERM);
        $this->waitExit($sup);

        $supervisor = array_values(array_filter($this->marks(), static fn($m) => str_starts_with($m, 'supervisor ')));
        foreach ($supervisor as $mark) {
            self::assertStringNotContainsString('FAILED', $mark, "a forked worker took the supervisor's session:\n" . implode("\n", $this->marks()));
        }
    }

    #[DataProvider('databases')]
    public function test_a_supervisor_in_a_transaction_holds_the_worker_back_instead_of_losing_the_session(string $db): void
    {
        $this->useDatabase($db);

        $sup = $this->fork(static fn() => ForkTxnDaemon::start());
        self::assertTrue($this->pollUntil(
            fn() => count(array_filter($this->marks(), static fn($m) => str_starts_with($m, 'supervisor '))) >= 3,
            10.0,
        ), implode("\n", $this->marks()));
        posix_kill($sup, SIGTERM);
        $this->waitExit($sup);

        $marks = $this->marks();
        $opened = array_search('transaction opened', $marks, true);
        $committed = array_search('transaction committed', $marks, true);
        self::assertNotFalse($opened, implode("\n", $marks));
        self::assertNotFalse($committed, 'the supervisor\'s transaction survived to its commit: ' . implode("\n", $marks));
        $between = array_slice($marks, $opened + 1, $committed - $opened - 1);
        // The worker forked just before the hook opened the transaction may still report
        // in this window; what must not happen is a new start (a fork) while it is open.
        self::assertSame([], array_values(array_filter($between, static fn($m) => str_starts_with($m, 'supervisor '))),
            'no worker is forked while the supervisor holds a transaction');
        self::assertGreaterThan(
            $committed,
            max(array_keys(array_filter($marks, static fn($m) => str_starts_with($m, 'supervisor ')))),
            'and the held-back worker starts once the transaction is committed',
        );
        foreach (array_filter($marks, static fn($m) => str_starts_with($m, 'supervisor ')) as $mark) {
            self::assertStringNotContainsString('FAILED', $mark, implode("\n", $marks));
        }
    }

    #[DataProvider('databases')]
    public function test_a_dead_connection_does_not_pass_for_an_open_transaction(string $db): void
    {
        // pdo_pgsql reports inTransaction() = true for a connection whose last query died
        // with it; that must not refuse every fork from then on.
        $this->useDatabase($db);

        $this->runParent(static function () use ($db): void {
            $session = substr(ForkProbe::session(), strlen('session '));
            $kill = $db === 'pg' ? "SELECT pg_terminate_backend({$session})" : "KILL {$session}";
            $config = new (ForkProbe::config())();
            $config->setUp();
            $config->connection()->exec($kill);                    // killed from another session
            usleep(200_000);
            ForkProbe::mark('after kill ' . ForkProbe::session());  // fails: the connection is dead

            $engine = new SyncEngine(concurrency: 0, grace: 5.0);
            try {
                $engine->enter(static function () use ($engine): void {
                    $engine->spawn(static function (): void {
                    });
                });
                ForkProbe::mark('spawned');
            } catch (PpaPoolException $e) {
                ForkProbe::mark('refused ' . $e->getMessage());
            }
            ForkProbe::mark('then ' . ForkProbe::session());
            ForkProbe::mark('done');
        });

        $marks = $this->marks();
        self::assertStringContainsString('FAILED', $marks[0], 'the setup must have killed the connection: ' . implode("\n", $marks));
        self::assertContains('spawned', $marks, implode("\n", $marks));
        self::assertStringStartsWith('then session ', $marks[2], 'the parent reconnects: ' . implode("\n", $marks));
    }
}
