<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Inc\Printer;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A missing optional package must produce an instruction, not a stack trace.
 *
 * {@see DepSupport::demand()} throws a two-line message — what needed the package, and
 * the `composer require` that installs it. Both `call db` and `call make` caught that
 * exception and handed its **string** to `Printer::printError()`, which takes a
 * `Throwable`: the refusal died as a `TypeError` and the operator got a trace of the
 * framework's own internals instead of the one line telling them what to install.
 *
 * The wording was already careful; nothing reached the screen to read it. This pins the
 * rendering so it cannot regress into that shape again.
 */
final class MissingDependencyRefusalTest extends TestCase
{
    private function render(string $message): string
    {
        ob_start();
        Printer::printMissingDependency($message);

        // Colour codes are noise for these assertions.
        return preg_replace('/\e\[[\d;]*m/', '', (string) ob_get_clean());
    }

    public function test_the_demand_message_survives_to_the_screen(): void
    {
        // The package is installed in this repository, so the refusal has to be staged:
        // what is under test is the rendering, not the detection.
        $prop = new \ReflectionProperty(DepSupport::class, 'installed');
        $original = $prop->getValue();
        $prop->setValue(null, [Dep::Ppa->value => false]);

        try {
            DepSupport::demand(Dep::Ppa, "The 'db' command");
            self::fail('demand() must throw when the package is absent');
        } catch (RuntimeException $e) {
            $out = $this->render($e->getMessage());
        } finally {
            $prop->setValue(null, $original);
        }

        self::assertStringContainsString("The 'db' command needs", $out, 'says what needed it');
        self::assertStringContainsString('composer require flytachi/winter-ppa', $out, 'and how to get it');
    }

    public function test_the_problem_is_a_warning_and_the_remedy_is_information(): void
    {
        $out = $this->render("Something needs a package, which is not installed.\nAdd it with:  composer require acme/pkg");

        $lines = array_values(array_filter(explode("\n", $out), static fn(string $l): bool => trim($l) !== ''));

        self::assertCount(2, $lines);
        self::assertStringContainsString('[!]', $lines[0], 'the problem is a warning');
        self::assertStringContainsString('[i]', $lines[1], 'the command to run is information');
    }

    public function test_a_single_line_message_still_renders(): void
    {
        $out = $this->render('Just one line.');

        self::assertStringContainsString('[!] Just one line.', $out);
        self::assertStringNotContainsString('[i]', $out);
    }
}
