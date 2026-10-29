<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use PHPUnit\Framework\TestCase;

/**
 * `call cfg docker` against a real project in a child process.
 *
 * It copies a template the developer then edits, so running it again must not wipe the
 * edits: it used to overwrite every file and report each one as created.
 */
final class CfgDockerTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wk_cfgdocker_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->project, 0777, true);
        file_put_contents($this->project . '/.env', "DEBUG=true\n");
        file_put_contents($this->project . '/bootstrap.php', sprintf(<<<'PHP'
            <?php
            require %s;

            #[\Flytachi\Winter\Kernel\App\Attribute\EnableHttp]
            final class CfgDockerApplication extends \Flytachi\Winter\Kernel\WinterApplication
            {
            }

            CfgDockerApplication::run($argv);
            PHP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    /** @return array{int, string} */
    private function call(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->project . '/bootstrap.php', 'cfg', 'docker', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $output)];
    }

    public function test_the_first_run_creates_the_template(): void
    {
        [$code, $output] = $this->call();

        self::assertSame(0, $code, $output);
        foreach (['Dockerfile', 'docker-compose.yml', '.dockerignore', 'docker/entrypoint.sh'] as $file) {
            self::assertFileExists($this->project . '/' . $file);
            self::assertMatchesRegularExpression('#' . preg_quote($file, '#') . ' \.+ \[CREATED\]#', $output);
        }
        self::assertTrue(is_executable($this->project . '/docker/entrypoint.sh'));
    }

    public function test_a_second_run_keeps_what_was_edited(): void
    {
        $this->call();
        file_put_contents($this->project . '/docker-compose.yml', "# edited\n", FILE_APPEND);

        [, $output] = $this->call();

        self::assertStringEndsWith("# edited\n", (string) file_get_contents($this->project . '/docker-compose.yml'));
        self::assertMatchesRegularExpression('/docker-compose\.yml \.+ \[EXISTS\]/', $output);
        self::assertStringContainsString('call cfg docker -f', $output);
    }

    public function test_f_overwrites(): void
    {
        $this->call();
        file_put_contents($this->project . '/docker-compose.yml', "# edited\n", FILE_APPEND);

        [, $output] = $this->call('-f');

        self::assertStringNotContainsString('# edited', (string) file_get_contents($this->project . '/docker-compose.yml'));
        self::assertMatchesRegularExpression('/docker-compose\.yml \.+ \[OVERWRITTEN\]/', $output);
    }
}
