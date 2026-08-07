<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\Core\KernelStore;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Tests\Process\Integration\Fixtures\DispatchMarkerProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The detached-launch chain end to end:
 *
 *   Process::dispatch() → thread launcher → `php vendor/bin/wKernelRunner --detach`
 *   → composer bin proxy → wKernelRunner → the project's bootstrap.php
 *   → WinterApplication::discoverAppClass() → ::executor() → boot → run()
 *
 * Every other Process integration test forks directly and never touches the launcher,
 * which is exactly why a broken runner went unnoticed: nothing executed it. This test
 * builds a throwaway project laid out like a real installation and dispatches into it
 * for real, so the whole chain — path resolution, app-class discovery, a fresh boot in
 * a new process, payload verification — has to work.
 */
#[Group('integration')]
final class DispatchRunnerTest extends TestCase
{
    /** Matches the fixture .env; the child verifies the payload with it. */
    private const string SECRET = '0123456789abcdef0123456789abcdef';

    private string $project = '';
    private ?string $originalKey = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('pcntl and posix are required for the integration tests.');
        }

        $this->project = sys_get_temp_dir() . '/wk_dispatch_' . getmypid() . '_' . bin2hex(random_bytes(4));
        $this->buildProject();

        // The launcher signs the payload with this; Kernel::init reads it when it binds
        // the launcher, and Dotenv is immutable so it will not overwrite it.
        $this->originalKey = $_ENV['WINTER_KEY'] ?? null;
        $_ENV['WINTER_KEY'] = self::SECRET;

        Kernel::init(
            pathRoot: $this->project,
            pathStorageRunnable: $this->project . '/storage/runnable',
        );

        // Kernel caches FileStorage by name against the path it was first built with.
        foreach (['runnable', 'storages', 'volatiles'] as $cache) {
            (new \ReflectionProperty(KernelStore::class, $cache))->setValue(null, []);
        }

        @unlink(DispatchMarkerProcess::markerPath());
    }

    protected function tearDown(): void
    {
        $pid = $this->markerPid();
        if ($pid !== null && $this->isAlive($pid)) {
            @posix_kill($pid, SIGTERM);
            $deadline = microtime(true) + 4.0;
            while ($this->isAlive($pid) && microtime(true) < $deadline) {
                usleep(50_000);
            }
            @posix_kill($pid, SIGKILL);
        }

        @unlink(DispatchMarkerProcess::markerPath());
        $this->removeTree($this->project);

        if ($this->originalKey === null) {
            unset($_ENV['WINTER_KEY']);
        } else {
            $_ENV['WINTER_KEY'] = $this->originalKey;
        }
    }

    public function test_dispatch_boots_the_application_in_a_detached_process(): void
    {
        $log = $this->project . '/runner.log';

        $pid = DispatchMarkerProcess::dispatch(output: $log);

        self::assertGreaterThan(0, $pid, 'the launcher returns the spawned shell PID');

        $childPid = $this->awaitMarker(10.0);

        // The runner writes boot failures here ("No application class found",
        // "bootstrap.php not found", a signature mismatch...), so an empty log is
        // the proof that the whole chain got through cleanly.
        self::assertSame('', trim((string) @file_get_contents($log)), 'the runner reported no error');
        self::assertNotNull($childPid, 'the dispatched process reached run() and wrote its marker');
        self::assertTrue($this->isAlive($childPid), 'it keeps running detached from this test process');
    }

    // ── fixture project ────────────────────────────────────────────────────────

    /**
     * Lays out a throwaway project the way composer installs one, because the runner
     * resolves the project root from its own location (`dirname(__DIR__, 3)`):
     *
     *   <project>/bootstrap.php
     *   <project>/vendor/bin/wKernelRunner              (composer bin proxy)
     *   <project>/vendor/flytachi/winter-kernel/wKernelRunner
     */
    private function buildProject(): void
    {
        $repo = dirname(__DIR__, 3);
        $pkg  = $this->project . '/vendor/flytachi/winter-kernel';

        @mkdir($this->project . '/vendor/bin', 0777, true);
        @mkdir($pkg, 0777, true);
        @mkdir($this->project . '/storage/runnable', 0777, true);

        // Copied, never symlinked: PHP resolves __DIR__ through symlinks, which would
        // point the runner at this repository instead of the throwaway project.
        copy($repo . '/wKernelRunner', $pkg . '/wKernelRunner');

        file_put_contents(
            $this->project . '/vendor/bin/wKernelRunner',
            "#!/usr/bin/env php\n<?php\nreturn include __DIR__ . '/..' . '/flytachi/winter-kernel/wKernelRunner';\n",
        );

        // The project's single entry: load the autoloader, declare the app class.
        // The kernel's own autoloader also exposes the Tests\ namespace, so the child
        // can autoload the dispatched fixture process.
        file_put_contents($this->project . '/bootstrap.php', <<<PHP
            <?php
            declare(strict_types=1);
            require '{$repo}/vendor/autoload.php';

            #[\Flytachi\Winter\Kernel\App\Attribute\EnableWeb]
            final class WkDispatchFixtureApp extends \Flytachi\Winter\Kernel\WinterApplication
            {
                public static function main(array \$argv): never { parent::run(\$argv); }
            }
            PHP);

        file_put_contents($this->project . '/.env', "WINTER_KEY=" . self::SECRET . "\nLOG_LEVEL=\n");
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Waits for the detached child to announce itself, returning its PID. */
    private function awaitMarker(float $timeout): ?int
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $pid = $this->markerPid();
            if ($pid !== null) {
                return $pid;
            }
            usleep(100_000);
        }

        return null;
    }

    private function markerPid(): ?int
    {
        $raw = @file_get_contents(DispatchMarkerProcess::markerPath());
        return is_string($raw) && trim($raw) !== '' ? (int) trim($raw) : null;
    }

    /** posix_getpgid needs no permission, so it works across users. */
    private function isAlive(int $pid): bool
    {
        return $pid > 0 && @posix_getpgid($pid) !== false;
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
