<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Console;

use Flytachi\Winter\Console\Command\Db;
use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `call db --help` says which package it needs.
 *
 * The page is reachable without that package, and that is the whole reason this matters:
 * `Cmd::script()` calls `isHelp()` before `handle()` and exits there, so the dependency
 * check the command performs at run time is never reached. Someone reading the help saw
 * four subcommands, five flags and seven examples, none of which can run, and no hint
 * why — the refusal only arrived after they tried one.
 *
 * The command stays gated as a whole, `ping` included: half of it working would read as
 * "this is broken" rather than "this is missing".
 */
final class DbHelpDependencyTest extends TestCase
{
    private array $original = [];

    protected function setUp(): void
    {
        $prop = new ReflectionProperty(DepSupport::class, 'installed');
        $this->original = $prop->getValue();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(DepSupport::class, 'installed')->setValue(null, $this->original);
    }

    private function help(bool $installed): string
    {
        new ReflectionProperty(DepSupport::class, 'installed')
            ->setValue(null, [Dep::Ppa->value => $installed]);

        ob_start();
        Db::help();

        // The colour codes are noise for these assertions.
        return preg_replace('/\e\[[\d;]*m/', '', (string) ob_get_clean());
    }

    // ── Stated either way ─────────────────────────────────────────────────────

    public function test_the_package_is_named_even_when_it_is_installed(): void
    {
        $help = $this->help(installed: true);

        self::assertStringContainsString('Requires', $help);
        self::assertStringContainsString('flytachi/winter-ppa', $help);
    }

    /** Where the reader looks first — before the list of things to try. */
    public function test_the_requirement_comes_before_the_commands(): void
    {
        $help = $this->help(installed: true);

        self::assertLessThan(
            strpos($help, 'migrate'),
            strpos($help, 'Requires'),
            'the requirement has to precede the menu it qualifies',
        );
    }

    // ── Louder when it is missing ─────────────────────────────────────────────

    public function test_a_missing_package_is_called_out(): void
    {
        $help = $this->help(installed: false);

        self::assertStringContainsString('Not installed', $help);
        self::assertStringContainsString('refuse to run', $help);
    }

    public function test_a_missing_package_is_given_its_install_line(): void
    {
        self::assertStringContainsString(
            'composer require flytachi/winter-ppa',
            $this->help(installed: false),
        );
    }

    public function test_an_installed_package_produces_no_warning(): void
    {
        $help = $this->help(installed: true);

        self::assertStringNotContainsString('Not installed', $help);
        self::assertStringNotContainsString('composer require', $help);
    }

    /**
     * Help is still printed in full. Refusing it would hide the one page explaining what
     * the command is and why that package is worth installing.
     */
    public function test_the_rest_of_the_help_is_printed_anyway(): void
    {
        $help = $this->help(installed: false);

        foreach (['ping', 'migrate', 'sql', 'pool', 'Usage', 'Flags', 'Examples'] as $section) {
            self::assertStringContainsString($section, $help);
        }
    }
}
