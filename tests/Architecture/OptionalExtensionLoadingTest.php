<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A test file must be loadable without the optional extensions.
 *
 * PHPUnit's suite loader `require_once`s every `*Test.php` before it runs anything, and
 * PHP resolves a parent class the moment the file is parsed. So a fixture written as
 * `class Spy extends \Swoole\Http\Response` inside a test file does not skip where Swoole
 * is absent — it takes the whole run down at collection time with
 * `Class "Swoole\Http\Response" not found`, before a single `markTestSkipped()` can speak.
 *
 * That is exactly how CI broke: the machine installs `pdo`, `pdo_pgsql`, `pdo_mysql` and
 * `mbstring` and nothing else, so `vendor/bin/phpunit` exited 255 with no test result at
 * all. Locally, where Swoole is installed, everything looked green.
 *
 * The rule: a fixture that extends an optional extension's class lives in its own file.
 * It matches no test suffix, so the loader ignores it, and the autoloader pulls it in only
 * when a test names it — by which point the test has already skipped if it had to.
 */
final class OptionalExtensionLoadingTest extends TestCase
{
    /** Extensions the kernel treats as optional; none may be needed to *parse* a test. */
    private const array OPTIONAL = ['Swoole', 'Redis'];

    public function test_no_test_file_extends_an_optional_extensions_class(): void
    {
        $offenders = [];

        foreach ($this->testFiles() as $file) {
            $src = file_get_contents($file);

            foreach (self::OPTIONAL as $ext) {
                $pattern = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+\s+'
                    . '(?:extends|implements)\s+\\\\?' . $ext . '\\\\/mi';

                if (preg_match($pattern, $src)) {
                    $offenders[] = basename($file) . " → {$ext}\\…";
                }
            }
        }

        sort($offenders);

        self::assertSame(
            [],
            $offenders,
            'Move such a fixture into its own file — a test file must parse without the extension.',
        );
    }

    /** @return list<string> */
    private function testFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
