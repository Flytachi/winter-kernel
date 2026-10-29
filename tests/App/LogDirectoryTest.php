<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * Where file logs go: `LOG_DIR`, relative to the project root unless absolute, and
 * `<root>/logs` without it — a file per channel. Nothing is created unless logs go to
 * files. `LOG_FILE` is refused by name: it named one file for both channels, and after
 * an upgrade logs quietly landing somewhere else are logs nobody finds.
 *
 * Each case boots the kernel in a fresh PHP process, as the environment is read once.
 */
final class LogDirectoryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk_logdir_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        file_put_contents($this->root . '/probe.php', sprintf(
            "<?php\nrequire %s;\n"
            // As the application's boot does: a refusal is one line on stderr, exit 1.
            . "try { Flytachi\\Winter\\Kernel\\Kernel::init(pathRoot: %s); }\n"
            . "catch (Throwable \$e) { fwrite(STDERR, \$e->getMessage()); exit(1); }\n"
            . "Flytachi\\Winter\\Logger\\LoggerFactory::getLogger('probe')->info('hello');\n"
            . "echo Flytachi\\Winter\\Kernel\\Kernel::\$pathLogs;\n",
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->root, true),
        ));
    }

    protected function tearDown(): void
    {
        @chmod($this->root . '/locked', 0777);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function boot(array $env): array
    {
        file_put_contents($this->root . '/.env', implode("\n", array_map(
            static fn(string $k, string $v): string => "{$k}={$v}",
            array_keys($env),
            $env,
        )) . "\n");
        exec(sprintf('cd %s && XDEBUG_MODE=off %s probe.php 2>&1', escapeshellarg($this->root), escapeshellarg(PHP_BINARY)), $out, $code);
        return [$code, implode("\n", $out)];
    }

    /** @return list<string> */
    private function logFiles(string $dir): array
    {
        return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
    }

    public function test_logs_to_stdout_create_no_directory(): void
    {
        [$code] = $this->boot(['LOG_LEVEL' => 'info']);

        self::assertSame(0, $code);
        self::assertDirectoryDoesNotExist($this->root . '/logs');
    }

    public function test_file_logs_go_to_logs_in_the_project_root_by_default(): void
    {
        [$code, $out] = $this->boot(['LOG_LEVEL' => 'info', 'LOG_OUTPUT' => 'file']);

        self::assertSame(0, $code, $out);
        self::assertSame($this->root . '/logs', $out);
        self::assertNotEmpty(array_filter($this->logFiles($this->root . '/logs'), static fn(string $f): bool => str_starts_with($f, 'sys')));
    }

    public function test_a_relative_log_dir_counts_from_the_root(): void
    {
        [$code, $out] = $this->boot(['LOG_LEVEL' => 'info', 'LOG_OUTPUT' => 'file', 'LOG_DIR' => 'var/log']);

        self::assertSame(0, $code, $out);
        self::assertSame($this->root . '/var/log', $out);
        self::assertNotEmpty($this->logFiles($this->root . '/var/log'));
        self::assertDirectoryDoesNotExist($this->root . '/logs');
    }

    public function test_an_absolute_log_dir_is_taken_as_it_is(): void
    {
        $dir = $this->root . '/elsewhere/winter';
        [$code, $out] = $this->boot(['LOG_LEVEL' => 'info', 'LOG_OUTPUT' => 'file', 'LOG_DIR' => $dir]);

        self::assertSame(0, $code, $out);
        self::assertSame($dir, $out);
        self::assertNotEmpty($this->logFiles($dir));
    }

    public function test_log_file_is_refused_by_name(): void
    {
        [$code, $out] = $this->boot(['LOG_LEVEL' => 'info', 'LOG_OUTPUT' => 'file', 'LOG_FILE' => '/tmp/app.log']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('LOG_FILE is no longer read: set LOG_DIR', $out);
    }

    /** A directory that cannot be written stops the boot — not the first log line. */
    public function test_a_log_dir_that_cannot_be_written_stops_the_boot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes anywhere.');
        }
        mkdir($this->root . '/locked', 0555);

        [$code, $out] = $this->boot(['LOG_LEVEL' => 'info', 'LOG_OUTPUT' => 'file', 'LOG_DIR' => 'locked/app']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('cannot be written by this user', $out);
    }
}
