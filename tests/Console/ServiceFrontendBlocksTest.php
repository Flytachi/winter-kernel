<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Kernel\App\FrontBuild;
use Flytachi\Winter\Console\Command\Service;
use PHPUnit\Framework\TestCase;

/** The frontends' blocks `call service docker` writes into the last stage. */
final class ServiceFrontendBlocksTest extends TestCase
{
    private static function build(string $context): FrontBuild
    {
        return new FrontBuild($context, '.output/public', ['package.json'], 'pnpm install', 'pnpm run generate');
    }

    private static function outputs(array $builds): string
    {
        return (string) Service::frontendBlocks($builds)[1];
    }

    public function test_a_folder_inside_another_is_emptied_with_it(): void
    {
        $out = self::outputs(['web' => self::build('web'), 'admin' => self::build('web/admin')]);

        self::assertSame(1, substr_count($out, 'RUN find '), "the inner folder went with the outer one:\n{$out}");
        self::assertStringContainsString('RUN find ./web -mindepth 1 -delete', $out);
        self::assertStringContainsString('./web/.output/public', $out);
        self::assertStringContainsString('./web/admin/.output/public', $out, 'and still gets its build');
        self::assertLessThan(strpos($out, 'COPY --from=frontend-web '), strpos($out, 'RUN find'));
    }

    public function test_a_folder_inside_another_declared_first_is_emptied_with_it_too(): void
    {
        $out = self::outputs(['admin' => self::build('web/admin'), 'web' => self::build('web')]);

        self::assertSame(1, substr_count($out, 'RUN find '), $out);
        self::assertStringContainsString('RUN find ./web -mindepth 1 -delete', $out);
    }

    public function test_folders_side_by_side_are_each_emptied(): void
    {
        $out = self::outputs(['web' => self::build('web'), 'webadmin' => self::build('webadmin')]);

        self::assertStringContainsString('RUN find ./web -mindepth 1 -delete', $out);
        self::assertStringContainsString('RUN find ./webadmin -mindepth 1 -delete', $out, 'a prefix of a name is not a folder above it');
    }

    public function test_a_development_build_gets_node_modules_after_the_folders_are_emptied(): void
    {
        $out = self::outputs(['web' => self::build('web'), 'admin' => self::build('web/admin')]);

        $dev = strpos($out, 'RUN if [ "$DEV" = "true" ]; then mkdir -p ./web/node_modules ./web/admin/node_modules; fi');
        self::assertNotFalse($dev, "a winter-owned mount point for each node_modules volume:\n{$out}");
        self::assertGreaterThan(strrpos($out, 'COPY --from'), $dev, 'after the emptying and the copies, or it is gone');
        self::assertStringContainsString("ARG DEV=false\n", $out);
    }
}
