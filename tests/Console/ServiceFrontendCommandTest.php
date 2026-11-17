<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Kernel\App\FrontBuild;
use PHPUnit\Framework\TestCase;

/**
 * `call service docker` with a frontend: its build gets a stage of its own before the
 * image's last stage, and its output is copied in after the code; running it again
 * changes nothing, and without frontends both blocks go.
 */
final class ServiceFrontendCommandTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wk_service_front_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->project, 0777, true);
        file_put_contents($this->project . '/composer.json', '{"autoload":{"psr-4":{"Main\\\\":"main/"}}}');
        file_put_contents($this->project . '/.env', "DEBUG=true\n");
        file_put_contents($this->project . '/bootstrap.php', sprintf(<<<'PHP'
            <?php
            require %s;

            use Flytachi\Winter\Kernel\App\Components;
            use Flytachi\Winter\Kernel\App\FrontBuild;
            use Flytachi\Winter\Kernel\App\FrontSite;
            use Flytachi\Winter\Kernel\App\ServiceImage;

            final class AdminFront implements \Flytachi\Winter\Kernel\App\Frontend
            {
                public function site(): FrontSite
                {
                    return new FrontSite('admin', __DIR__ . '/admin/.output/public', port: 9091, routes: ['/admin']);
                }

                public function image(): ServiceImage
                {
                    return new ServiceImage(packages: getenv('SSR_FRONT') ? ['nodejs'] : [], devPackages: ['nodejs', 'npm']);
                }

                public function build(): ?FrontBuild
                {
                    return new FrontBuild('admin', '.output/public', ['package.json', 'pnpm-lock.yaml'],
                        'pnpm install --frozen-lockfile', 'pnpm generate');
                }

                public function devServer(): ?\Flytachi\Winter\Kernel\App\FrontProgram
                {
                    return null;
                }

                public function hints(bool $dev): array
                {
                    return [];
                }
            }

            #[\Flytachi\Winter\Kernel\App\Attribute\EnableHttp]
            final class FrontCommandApplication extends \Flytachi\Winter\Kernel\WinterApplication
            {
                protected static function components(Components $components): void
                {
                    if (getenv('NO_FRONTENDS') === false) {
                        $components->frontend(new AdminFront());
                    }
                }
            }

            FrontCommandApplication::run($argv);
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

    private function dockerfile(): string
    {
        return (string) file_get_contents($this->project . '/Dockerfile');
    }

    public function test_a_frontend_gets_a_build_stage_and_its_output_copied(): void
    {
        [, $output] = $this->call(['service', 'docker']);
        $file = $this->dockerfile();

        $stage = strpos($file, 'FROM node:24-alpine AS frontend-admin');
        $lastFrom = strrpos($file, "\nFROM flytachi/winter");
        $code = strpos($file, 'COPY --chown=winter:winter . .');
        $copy = strpos($file, 'COPY --from=frontend-admin --chown=winter:winter /front/.output/public ./admin/.output/public');

        self::assertNotFalse($stage, $output . $file);
        self::assertLessThan($lastFrom, $stage, 'the stage comes before the last stage');
        self::assertStringContainsString("COPY admin/package.json admin/pnpm-lock.yaml ./\nRUN pnpm install --frozen-lockfile\nCOPY admin/ ./\nRUN pnpm generate", $file);
        self::assertNotFalse($copy);
        self::assertGreaterThan($code, $copy, 'the output is copied after the code, or the code would overwrite it');
        $drop = strpos($file, "RUN find ./admin -mindepth 1 -delete\n");
        self::assertNotFalse($drop, 'the sources the code brought are dropped: the image keeps the build only');
        self::assertGreaterThan($code, $drop);
        self::assertLessThan($copy, $drop, 'before the build is copied in');
        self::assertLessThan(strpos($file, 'ENTRYPOINT'), $copy);
        self::assertMatchesRegularExpression('#Dockerfile \.+ \[UPDATED\]#', $output);
    }

    public function test_a_second_run_changes_nothing_with_a_frontend(): void
    {
        $this->call(['service', 'docker']);
        $first = $this->dockerfile();

        [, $output] = $this->call(['service', 'docker']);

        self::assertSame($first, $this->dockerfile());
        self::assertSame(1, substr_count($first, 'AS frontend-admin'));
        self::assertMatchesRegularExpression('#Dockerfile \.+ \[UNCHANGED\]#', $output);
    }

    public function test_without_frontends_the_blocks_go(): void
    {
        $before = $this->dockerfile();
        $this->call(['service', 'docker']);

        $this->call(['service', 'docker'], ['NO_FRONTENDS' => '1']);

        self::assertSame($before, $this->dockerfile(), 'the file is as it was before the blocks');
    }

    public function test_the_dry_run_shows_the_stage_and_writes_nothing(): void
    {
        $before = $this->dockerfile();

        [, $output] = $this->call(['service', 'docker', '--dry']);

        self::assertSame($before, $this->dockerfile());
        self::assertStringContainsString('FROM node:24-alpine AS frontend-admin', $output);
    }

    public function test_a_build_takes_file_names_only_in_its_manifest(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manifest is a list of file names');
        new FrontBuild('admin', '.output/public', ['../package.json'], 'pnpm install', 'pnpm generate');
    }

    public function test_the_dockerignore_leaves_out_what_a_frontend_builds(): void
    {
        $ignore = (string) file_get_contents($this->project . '/.dockerignore');

        foreach (['**/node_modules', '**/.nuxt', '**/.output'] as $entry) {
            self::assertStringContainsString($entry, $ignore);
        }
    }

    public function test_a_parser_directive_stays_on_the_first_line(): void
    {
        $dockerfile = "# syntax=docker/dockerfile:1\nFROM flytachi/winter:8.5\nCOPY . .\nENTRYPOINT [\"x\"]";
        [$stages] = \Flytachi\Winter\Console\Command\Service::frontendBlocks([
            'admin' => new FrontBuild('admin', '.output/public', ['package.json'], 'npm ci', 'npm run generate'),
        ]);

        $with = \Flytachi\Winter\Console\Command\Service::withFrontendStages($dockerfile, $stages);

        self::assertStringStartsWith("# syntax=docker/dockerfile:1\n", $with);
        self::assertLessThan(strpos($with, 'FROM flytachi/winter'), strpos($with, 'AS frontend-admin'));
    }

    public function test_a_continued_line_saying_from_is_not_a_stage(): void
    {
        $dockerfile = "FROM flytachi/winter:8.5\nRUN echo \\\n  from here\nCOPY . .";
        [$stages] = \Flytachi\Winter\Console\Command\Service::frontendBlocks([
            'admin' => new FrontBuild('admin', '.output/public', ['package.json'], 'npm ci', 'npm run generate'),
        ]);

        $with = \Flytachi\Winter\Console\Command\Service::withFrontendStages($dockerfile, $stages);

        self::assertLessThan(strpos($with, 'FROM flytachi/winter'), strpos($with, 'AS frontend-admin'), 'before the real FROM');
    }

    public function test_a_dockerignore_that_keeps_node_modules_is_pointed_out(): void
    {
        file_put_contents($this->project . '/.dockerignore', ".git\n.env\n");

        [, $output] = $this->call(['service', 'docker']);

        self::assertStringContainsString(
            '.dockerignore does not leave out **/node_modules, **/.nuxt, **/.output',
            $output,
        );
        self::assertSame(".git\n.env\n", file_get_contents($this->project . '/.dockerignore'), 'a hint: the file is not touched');
    }

    public function test_a_dockerignore_that_leaves_them_out_is_quiet(): void
    {
        [, $output] = $this->call(['service', 'docker']);

        self::assertStringNotContainsString('.dockerignore does not leave out', $output);
    }

    public function test_without_frontends_the_dockerignore_is_not_looked_at(): void
    {
        file_put_contents($this->project . '/.dockerignore', ".git\n");

        [, $output] = $this->call(['service', 'docker'], ['NO_FRONTENDS' => '1']);

        self::assertStringNotContainsString('.dockerignore', $output);
    }

    public function test_a_root_only_pattern_does_not_cover_the_frontends_folder(): void
    {
        file_put_contents($this->project . '/.dockerignore', "node_modules\n/.nuxt\n.output\n");

        [, $output] = $this->call(['service', 'docker']);

        self::assertStringContainsString('.dockerignore does not leave out **/node_modules, **/.nuxt, **/.output', $output);
    }

    public function test_the_frontends_own_folder_covers_it(): void
    {
        file_put_contents($this->project . '/.dockerignore', "admin/node_modules\n/admin/.nuxt/\nadmin/.output\n");

        [, $output] = $this->call(['service', 'docker']);

        self::assertStringNotContainsString('.dockerignore does not leave out', $output);
    }

    public function test_a_frontends_image_reaches_the_dockerfile(): void
    {
        $this->call(['service', 'docker'], ['SSR_FRONT' => '1']);
        $file = $this->dockerfile();

        self::assertStringContainsString('RUN apk add --no-cache nodejs', $file);
        self::assertStringContainsString(
            "ARG DEV=false\nRUN if [ \"\$DEV\" = \"true\" ]; then apk add --no-cache nodejs npm \\\n"
                . "    && mkdir -p /app/admin/node_modules && chown winter:winter /app/admin/node_modules; fi",
            $file,
            'the volume\'s mount point is winter\'s: a new volume takes its owner from it',
        );
    }

    public function test_the_dev_hint_names_the_node_modules_volume_and_the_build_arg(): void
    {
        [, $output] = $this->call(['service', 'docker']);

        self::assertStringContainsString('call run dev in Docker', $output);
        self::assertStringContainsString('DEV: ${DEV:-false}', $output);
        self::assertStringContainsString('admin-node-modules:/app/admin/node_modules', $output);
    }

    public function test_no_frontend_no_dev_hint(): void
    {
        [, $output] = $this->call(['service', 'docker'], ['NO_FRONTENDS' => '1']);

        self::assertStringNotContainsString('node_modules:', $output);
        self::assertStringNotContainsString('ARG DEV', $this->dockerfile());
    }
}
