<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use PHPUnit\Framework\TestCase;

/** A command that fails says so to whatever ran it: its exit code, not only its screen. */
final class PrinterErrorExitTest extends TestCase
{
    /** @return array{int, string} The exit code and what was printed. */
    private static function printError(string $args): array
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $code = "require {$autoload}; \\Flytachi\\Winter\\Console\\Inc\\Printer::printError(new \\RuntimeException('it broke'){$args});";
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
        return [$exit, implode("\n", $out)];
    }

    public function test_an_error_exits_with_one(): void
    {
        [$exit, $out] = self::printError('');

        self::assertSame(1, $exit, 'a script or a CI job sees the command failed');
        self::assertStringContainsString('it broke', $out);
    }

    public function test_an_error_exits_with_the_code_it_was_given(): void
    {
        self::assertSame(3, self::printError(', 3')[0]);
    }
}
