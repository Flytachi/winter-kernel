<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * A start that fails says why, in production mode too.
 *
 * With `DEBUG=false` the kernel turns PHP's error output off, and an exception
 * escaping the boot used to end the process with code 255 and no output at all —
 * the checks meant to fail loudly before traffic (an undeclared cache, an empty
 * manifest) failed in silence. Each case runs a real application in a child process,
 * because what is under test is exactly what reaches the terminal.
 */
final class BootFailureTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wk_boot_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->project);

        // No #[Enable*] at all: `run` refuses to start an application that does nothing.
        file_put_contents($this->project . '/bootstrap.php', sprintf(<<<'PHP'
            <?php
            require %s;

            final class BrokenApplication extends \Flytachi\Winter\Kernel\WinterApplication
            {
            }

            BrokenApplication::run(['call', 'run']);
            PHP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)));
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
        // The boot also wrote its volatile store beside the system temp dir, named after the project.
    }

    /**
     * @return array{int, string, string} Exit code, stdout, stderr.
     */
    private function boot(bool $debug): array
    {
        file_put_contents($this->project . '/.env', 'DEBUG=' . ($debug ? 'true' : 'false') . "\n");

        $process = proc_open(
            [PHP_BINARY, $this->project . '/bootstrap.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    public function test_production_mode_still_says_why_the_start_failed(): void
    {
        [$code, , $stderr] = $this->boot(debug: false);

        self::assertSame(1, $code, 'a failed start exits with 1, not the 255 of an uncaught error');
        self::assertStringContainsString('winter: boot failed', $stderr);
        self::assertStringContainsString('ApplicationConfigException', $stderr);
        self::assertStringContainsString('No components declared', $stderr);
        self::assertStringNotContainsString('#0 ', $stderr, 'no stack trace outside debug mode');
    }

    public function test_debug_mode_adds_the_stack_trace(): void
    {
        [$code, , $stderr] = $this->boot(debug: true);

        self::assertSame(1, $code);
        self::assertStringContainsString('No components declared', $stderr);
        self::assertStringContainsString('#0 ', $stderr);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
