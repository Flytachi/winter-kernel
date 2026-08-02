<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The kernel is addressed by role, like every sibling package, not by generation.
 *
 * `winter-cdo` is `Flytachi\Winter\Cdo`, `winter-logger` is `Flytachi\Winter\Logger`;
 * the kernel follows the same rule. The former root encoded a generation number, so
 * moving to the next one would have meant renaming the entire tree — a job the version
 * field in composer.json already does.
 */
final class NamespaceTest extends TestCase
{
    /**
     * Directories holding no shippable code: build output, runtime artefacts, and the
     * design notes, which record the previous name on purpose and must keep it.
     */
    private const array SKIP = ['/vendor/', '/.git/', '/.idea/', '/node_modules/', '/storage/', '/doc/'];

    public function test_the_kernel_root_is_addressed_by_role(): void
    {
        self::assertTrue(class_exists('Flytachi\Winter\Kernel\Kernel'));
        self::assertTrue(class_exists('Flytachi\Winter\Kernel\WinterApplication'));
    }

    public function test_the_console_keeps_its_own_root(): void
    {
        // Deliberately a second root: the CLI is not the kernel's runtime API, and this
        // boundary is what lets console/ move to its own package without a second rename.
        self::assertTrue(class_exists('Flytachi\Winter\Console\Stereotype\CmdCustom'));
    }

    public function test_no_generation_suffix_survives_anywhere(): void
    {
        // Assembled rather than written out, so this test does not match its own source.
        $stale = 'Winter\\' . 'K2';
        $root = dirname(__DIR__, 2);
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            $path = $file->getPathname();

            if (!$file->isFile()) {
                continue;
            }
            foreach (self::SKIP as $skip) {
                if (str_contains($path, $skip)) {
                    continue 2;
                }
            }
            if (str_contains((string) file_get_contents($path), $stale)) {
                $found[] = substr($path, strlen($root) + 1);
            }
        }

        sort($found);

        self::assertSame([], $found, 'These files still address the kernel by its old generation name.');
    }
}
