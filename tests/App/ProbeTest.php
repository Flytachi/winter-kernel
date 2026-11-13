<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\Probe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An alive check: what it checks, how often, how long one may take, and how many
 * failures in a row mean the program hangs.
 */
final class ProbeTest extends TestCase
{
    public function test_the_defaults_are_cautious(): void
    {
        $probe = Probe::command(['pg_isready', '-q']);

        self::assertSame(10.0, $probe->every);
        self::assertSame(5.0, $probe->timeout);
        self::assertSame(6, $probe->failures, 'about a minute without an answer');
        self::assertSame(['pg_isready', '-q'], $probe->command);
        self::assertNull($probe->address);
    }

    public function test_a_port_may_be_written_with_or_without_its_scheme(): void
    {
        self::assertSame('tcp://127.0.0.1:6379', Probe::tcp('127.0.0.1:6379')->address);
        self::assertSame('tcp://[::1]:6379', Probe::tcp('tcp://[::1]:6379')->address);
    }

    public function test_alive_takes_a_command_an_address_or_a_probe(): void
    {
        self::assertSame(['pg_isready'], Component::command(['pg'], alive: ['pg_isready'])->alive?->command);
        self::assertSame('tcp://127.0.0.1:5432', Component::command(['pg'], alive: 'tcp://127.0.0.1:5432')->alive?->address);
        $own = Probe::tcp('127.0.0.1:5432', every: 30.0, failures: 10);
        self::assertSame($own, Component::command(['pg'], alive: $own)->alive);
        self::assertNull(Component::command(['pg'])->alive, 'not checked unless asked');
    }

    /** @return iterable<string, array{\Closure(): Probe}> */
    public static function badProbes(): iterable
    {
        yield 'no command' => [static fn() => Probe::command([])];
        yield 'empty program' => [static fn() => Probe::command([''])];
        yield 'no port' => [static fn() => Probe::tcp('127.0.0.1')];
        yield 'port out of range' => [static fn() => Probe::tcp('127.0.0.1:0')];
        yield 'every 0' => [static fn() => Probe::tcp('127.0.0.1:1', every: 0.0)];
        yield 'timeout 0' => [static fn() => Probe::tcp('127.0.0.1:1', timeout: 0.0)];
        yield 'no failure to act on' => [static fn() => Probe::tcp('127.0.0.1:1', failures: 0)];
    }

    #[DataProvider('badProbes')]
    public function test_a_bad_probe_is_refused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }
}
