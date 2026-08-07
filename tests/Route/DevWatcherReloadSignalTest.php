<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route;

use Flytachi\Winter\Kernel\Route\DevWatcher;
use PHPUnit\Framework\TestCase;

/**
 * The restart request has to survive a process boundary.
 *
 * `run dev` restarts by re-exec'ing after `Server::start()` returns, and it decides
 * whether to do so by asking the watcher. But the watcher's poll timer is armed from
 * `onStart`, and the moment an application declares a companion — `#[EnableProcess]`,
 * `#[EnableDaemon]`, `#[EnableScheduler]` — Swoole runs that callback in a **manager
 * process of its own**. Measured: with no companion the flag set in the timer is visible
 * after `start()` returns; with one, the timer ran in pid B while `start()` was called in
 * pid A, so pid A read `false`, skipped the re-exec and exited 0.
 *
 * The symptom was exact and easy to misread as a crash: `↻ change … restarting…` printed,
 * then the server simply gone — with a zero exit code and nothing in any log. It survived
 * a long time because it never appears in an application without companions.
 */
final class DevWatcherReloadSignalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wk_devwatch_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        foreach (glob(sys_get_temp_dir() . '/winter-dev-reload-*') ?: [] as $marker) {
            @unlink($marker);
        }
    }

    /** Nothing changed, nothing requested. */
    public function test_a_fresh_watcher_asks_for_nothing(): void
    {
        self::assertFalse(new DevWatcher([$this->dir])->reloadRequested());
    }

    /**
     * The case that was broken: the request is made by one process and read by another.
     * A forked child stands in for the manager Swoole creates for a companion.
     */
    public function test_a_request_made_in_another_process_is_still_seen(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Needs ext-pcntl to cross a process boundary.');
        }

        $watcher = new DevWatcher([$this->dir]);

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // The child is the manager: it decides a restart is needed, then goes away.
            new \ReflectionMethod(DevWatcher::class, 'requestReload')->invoke($watcher);
            posix_kill(getmypid(), SIGKILL);
        }

        pcntl_waitpid($pid, $status);

        self::assertTrue(
            $watcher->reloadRequested(),
            'the parent must act on a restart requested by the manager process',
        );
    }

    /** Answered once: a stopped-and-started server must not restart itself again. */
    public function test_the_request_is_cleared_once_it_has_been_answered(): void
    {
        $watcher = new DevWatcher([$this->dir]);
        new \ReflectionMethod(DevWatcher::class, 'requestReload')->invoke($watcher);

        self::assertTrue($watcher->reloadRequested());
        self::assertFalse($watcher->reloadRequested(), 'the marker must not outlive its answer');
    }

    /** Two dev servers at once must not read each other's requests. */
    public function test_two_watchers_do_not_share_a_request(): void
    {
        $a = new DevWatcher([$this->dir]);
        $b = new DevWatcher([$this->dir . '/other']);

        new \ReflectionMethod(DevWatcher::class, 'requestReload')->invoke($a);

        self::assertFalse($b->reloadRequested(), 'a watcher over another tree is unaffected');
        self::assertTrue($a->reloadRequested());
    }
}
