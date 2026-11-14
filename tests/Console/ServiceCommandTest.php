<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Command\Service;
use PHPUnit\Framework\TestCase;

/**
 * `call service list | docker` against a real project in a child process: what the
 * declared services need goes into one Dockerfile block and into the compose override,
 * and running it again changes nothing that is already right.
 */
final class ServiceCommandTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wk_service_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->project, 0777, true);
        file_put_contents($this->project . '/composer.json', '{"autoload":{"psr-4":{"Main\\\\":"main/"}}}');
        file_put_contents($this->project . '/.env', "DEBUG=true\n");
        file_put_contents($this->project . '/bootstrap.php', sprintf(<<<'PHP'
            <?php
            require %s;

            use Flytachi\Winter\Kernel\App\Component;
            use Flytachi\Winter\Kernel\App\Components;
            use Flytachi\Winter\Kernel\App\Priority;
            use Flytachi\Winter\Kernel\App\ServiceImage;

            final class FakeDb implements \Flytachi\Winter\Kernel\App\Service
            {
                public function component(): Component
                {
                    return Component::command(['sleep', '60'], 'fakedb', priority: Priority::High, grace: 60.0);
                }

                public function image(): ServiceImage
                {
                    return new ServiceImage(
                        packages: ['fakedb18'],
                        directories: ['/var/lib/fakedb' => 0700],
                        volumes: ['fakedb-data' => '/var/lib/fakedb'],
                        env: ['FAKEDB' => '/var/lib/fakedb'],
                    );
                }
            }

            #[\Flytachi\Winter\Kernel\App\Attribute\EnableHttp]
            final class ServiceCommandApplication extends \Flytachi\Winter\Kernel\WinterApplication
            {
                protected static function components(Components $components): void
                {
                    if (getenv('NO_SERVICES') === false) {
                        $components->service(new FakeDb());
                    }
                }
            }

            ServiceCommandApplication::run($argv);
            PHP, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)));
        $this->call(['cfg', 'docker']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private function call(array $arguments, array $env = []): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->project . '/bootstrap.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), ...$env],
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) preg_replace('/\e\[[0-9;]*m/', '', (string) $output)];
    }

    private function file(string $name): string
    {
        return (string) @file_get_contents($this->project . '/' . $name);
    }

    public function test_docker_writes_the_block_before_the_code_and_the_override(): void
    {
        [, $output] = $this->call(['service', 'docker']);

        $dockerfile = $this->file('Dockerfile');
        self::assertStringContainsString('apk add --no-cache fakedb18', $dockerfile, $output);
        self::assertStringContainsString('ENV FAKEDB=/var/lib/fakedb', $dockerfile);
        self::assertLessThan(strpos($dockerfile, 'COPY --from=vendor'), strpos($dockerfile, '# winter:services'), 'before the code');
        self::assertGreaterThan(strrpos($dockerfile, 'FROM '), strpos($dockerfile, '# winter:services'), 'in the last stage');
        self::assertMatchesRegularExpression('#Dockerfile \.+ \[UPDATED\]#', $output);

        $override = $this->file('docker-compose.override.yml');
        self::assertStringContainsString("  server:\n    stop_grace_period: 100s\n", $override, 'Http 30 + fakedb 60 + 5, + 5 for the container');
        self::assertStringContainsString('      - fakedb-data:/var/lib/fakedb', $override);
        self::assertStringContainsString("volumes:\n  fakedb-data:\n", $override);
        self::assertStringNotContainsString('fakedb', $this->file('docker-compose.yml'), 'the project\'s own compose file is not edited');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->call(['service', 'docker']);
        $dockerfile = $this->file('Dockerfile');

        [, $output] = $this->call(['service', 'docker']);

        self::assertSame($dockerfile, $this->file('Dockerfile'));
        self::assertSame(1, substr_count($dockerfile, '# winter:services end'));
        self::assertMatchesRegularExpression('#Dockerfile \.+ \[UNCHANGED\]#', $output);
        self::assertMatchesRegularExpression('#docker-compose\.override\.yml \.+ \[UNCHANGED\]#', $output);
    }

    public function test_dry_shows_and_writes_nothing(): void
    {
        $before = $this->file('Dockerfile');

        [, $output] = $this->call(['service', 'docker', '--dry']);

        self::assertSame($before, $this->file('Dockerfile'));
        self::assertFileDoesNotExist($this->project . '/docker-compose.override.yml');
        self::assertStringContainsString('apk add --no-cache fakedb18', $output);
        self::assertStringContainsString('--dry: nothing was written.', $output);
    }

    public function test_without_services_the_block_and_the_override_go(): void
    {
        $this->call(['service', 'docker']);
        $before = $this->file('Dockerfile');

        $this->call(['service', 'docker'], ['NO_SERVICES' => '1']);

        self::assertStringNotContainsString('winter:services', $this->file('Dockerfile'));
        self::assertFileDoesNotExist($this->project . '/docker-compose.override.yml');
        self::assertSame(
            str_replace(["\n\n\n"], ["\n\n"], (string) preg_replace('/# winter:services.*?# winter:services end\n\n?/s', '', $before)),
            $this->file('Dockerfile'),
            'the file is as it was before the block',
        );
    }

    public function test_an_override_the_command_did_not_write_is_left_alone(): void
    {
        file_put_contents($this->project . '/docker-compose.override.yml', "services:\n  server:\n    environment:\n      X: 1\n");

        [, $output] = $this->call(['service', 'docker']);

        self::assertSame("services:\n  server:\n    environment:\n      X: 1\n", $this->file('docker-compose.override.yml'));
        self::assertStringContainsString('is not this command\'s: it is left alone', $output);
        self::assertStringContainsString('fakedb-data:/var/lib/fakedb', $output, 'what to add is printed');
    }

    public function test_list_names_each_service_and_what_it_needs(): void
    {
        [, $output] = $this->call(['service', 'list']);

        self::assertStringContainsString('FakeDb', $output);
        self::assertStringContainsString('the project', $output, 'not from a package');
        self::assertStringContainsString('High', $output);
        self::assertStringContainsString('fakedb18', $output);
        self::assertStringContainsString('fakedb-data → /var/lib/fakedb', $output);
    }

    public function test_app_info_says_the_component_comes_from_a_service(): void
    {
        [, $output] = $this->call(['app', 'info']);

        self::assertMatchesRegularExpression('/fakedb\s+Command\s+service\(FakeDb\)/', $output);
    }

    // ── The block itself ──────────────────────────────────────────────────────

    public function test_the_block_goes_above_the_comments_of_the_first_copy_of_the_last_stage(): void
    {
        $dockerfile = "FROM composer AS vendor\nCOPY a b\n\nFROM flytachi/winter:8.5\n\n# the code\nCOPY . .\nENTRYPOINT [\"x\"]";
        $block = "# winter:services — x\nENV A=1\n# winter:services end\n";

        $with = Service::withBlock($dockerfile, $block);

        self::assertSame(
            "FROM composer AS vendor\nCOPY a b\n\nFROM flytachi/winter:8.5\n\n# winter:services — x\nENV A=1\n# winter:services end\n\n# the code\nCOPY . .\nENTRYPOINT [\"x\"]",
            $with,
        );
        self::assertSame($with, Service::withBlock($with, $block), 'replaced in place, not added again');
        self::assertSame($dockerfile, Service::withBlock($with, null), 'removed, the file is as it was');
    }

    public function test_a_start_mark_without_its_end_is_not_guessed_at(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without "# winter:services end"');
        Service::withBlock("FROM x\n# winter:services — x\nENV A=1\nCOPY . .", "# winter:services — y\n# winter:services end\n");
    }
}
