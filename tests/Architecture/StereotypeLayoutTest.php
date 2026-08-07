<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every extension point lives in the Stereotype directory of its own layer.
 *
 * This is the rule the restructure exists to enforce: what an application extends is
 * addressable in one predictable place per layer, while the machinery supporting it
 * stays outside. Because these addresses are public API, a move that forgets one of
 * them has to fail here rather than in a downstream project.
 */
final class StereotypeLayoutTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function stereotypes(): array
    {
        return [
            'controller' => ['Flytachi\Winter\Kernel\Http\Stereotype\Controller'],
            'controller interface' => ['Flytachi\Winter\Kernel\Http\Stereotype\ControllerInterface'],
            'middleware' => ['Flytachi\Winter\Kernel\Http\Stereotype\Middleware'],
            'exception response' => ['Flytachi\Winter\Kernel\Http\Stereotype\ExceptionResponseBase'],
            'process' => ['Flytachi\Winter\Kernel\Process\Stereotype\Process'],
            'daemon' => ['Flytachi\Winter\Kernel\Process\Stereotype\Daemon'],
            'scheduler' => ['Flytachi\Winter\Kernel\Schedule\Stereotype\Scheduler'],
            'cmd custom' => ['Flytachi\Winter\Console\Stereotype\CmdCustom'],
            'cmd custom interface' => ['Flytachi\Winter\Console\Stereotype\CmdCustomInterface'],
        ];
    }

    #[DataProvider('stereotypes')]
    public function test_the_stereotype_is_addressable(string $fqcn): void
    {
        self::assertTrue(
            class_exists($fqcn) || interface_exists($fqcn),
            "{$fqcn} must exist — an application extends it, so its address is public API.",
        );
    }

    /**
     * Every Stereotype directory belongs to a layer — there is no orphan one at the root.
     *
     * The root directory existed for a single empty `Service` base class, a transliteration
     * of Spring's `@Service` **annotation** into inheritance. PHP allows one parent, so
     * extending it spent that slot on nothing: the container resolves by class name and
     * lifetime comes from `#[Singleton]`, so no part of the kernel ever read the base
     * class. Removing it leaves the rule without an exception.
     */
    public function test_no_stereotype_directory_sits_outside_a_layer(): void
    {
        self::assertDirectoryDoesNotExist(
            dirname(__DIR__, 2) . '/src/Stereotype',
            'A Stereotype directory belongs to its layer; a root one has no layer to speak for.',
        );
    }

    /**
     * The machinery a stereotype relies on is not itself a stereotype, so it must not
     * follow the extension point into the Stereotype directory.
     */
    public function test_the_middleware_contract_stays_out_of_the_stereotype_directory(): void
    {
        self::assertTrue(
            interface_exists('Flytachi\Winter\Kernel\Http\Middleware\MiddlewareInterface'),
            'MiddlewareInterface is a contract to implement, not a base class to extend.',
        );
        self::assertFalse(
            interface_exists('Flytachi\Winter\Kernel\Http\Stereotype\MiddlewareInterface'),
        );
    }
}
