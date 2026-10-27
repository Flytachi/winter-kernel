<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `env()` reads after the kernel boots: the variables the process was started
 * with, then `.env` for the rest — whatever `variables_order` says.
 *
 * With `GPCS`, the php.ini of most installations, PHP leaves `$_ENV` empty: a process
 * variable was invisible, and one that `.env` held as well was lost altogether — Dotenv
 * found it in `$_SERVER`, left it there and wrote nothing to `$_ENV`. An image without
 * a php.ini says `EGPCS` and behaved the other way, so one application read its settings
 * differently on a laptop and in a container.
 *
 * Each case boots the kernel in a fresh PHP process: the order is a startup setting.
 */
final class ProcessEnvironmentTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wk_env_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0777, true);
        file_put_contents($this->root . '/.env', "WK_IN_FILE=from-file\nWK_BOTH=from-file\n");
        file_put_contents($this->root . '/probe.php', sprintf(
            "<?php\nrequire %s;\n"
            . "Flytachi\\Winter\\Kernel\\Kernel::init(pathRoot: %s);\n"
            . "echo json_encode([env('WK_IN_FILE'), env('WK_BOTH'), env('WK_ONLY_PROCESS')]);\n",
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->root, true),
        ));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, array{string}> */
    public static function orders(): array
    {
        return ['GPCS (most php.ini files)' => ['GPCS'], 'EGPCS (no php.ini)' => ['EGPCS']];
    }

    #[DataProvider('orders')]
    public function test_the_process_environment_wins_over_env_file(string $order): void
    {
        $out = shell_exec(sprintf(
            'cd %s && WK_BOTH=from-process WK_ONLY_PROCESS=from-process XDEBUG_MODE=off %s -d variables_order=%s probe.php 2>&1',
            escapeshellarg($this->root),
            escapeshellarg(PHP_BINARY),
            $order,
        ));

        self::assertSame(['from-file', 'from-process', 'from-process'], json_decode((string) $out, true), (string) $out);
    }
}
