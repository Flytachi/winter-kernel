<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Core;

use Flytachi\Winter\Kernel\Core\Dep;
use Flytachi\Winter\Kernel\Core\DepSupport;
use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * What the kernel does when an optional package is not installed.
 *
 * PPA and Redis are `composer require` decisions. Every place the kernel reaches into
 * either therefore has a second path, and that path is normally unreachable here — both
 * are dev dependencies, so both are always present. These tests take the answer away and
 * walk the branch a real application without them takes.
 */
#[CoversClass(DepSupport::class)]
#[CoversClass(Dep::class)]
final class DepSupportTest extends TestCase
{
    protected function tearDown(): void
    {
        DepSupport::forget();
    }

    /** Pretends a package is absent, without uninstalling it. */
    private function without(Dep ...$deps): void
    {
        $map = [];
        foreach ($deps as $dep) {
            $map[$dep->value] = false;
        }
        new ReflectionProperty(DepSupport::class, 'installed')->setValue(null, $map);
    }

    /** @return iterable<string, array{Dep}> */
    public static function packages(): iterable
    {
        yield 'ppa' => [Dep::Ppa];
        yield 'redis' => [Dep::Redis];
    }

    #[DataProvider('packages')]
    public function test_both_packages_are_present_in_this_repository(Dep $dep): void
    {
        DepSupport::forget();

        self::assertTrue(DepSupport::has($dep), $dep->package() . ' is a dev dependency of the kernel');
    }

    #[DataProvider('packages')]
    public function test_demand_is_silent_when_the_package_is_there(Dep $dep): void
    {
        DepSupport::demand($dep, 'Something');

        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('packages')]
    public function test_demand_names_what_needed_it_and_how_to_get_it(Dep $dep): void
    {
        $this->without($dep);

        try {
            DepSupport::demand($dep, "The 'x' command");
            self::fail('a missing optional package must be reported, not fatal');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("The 'x' command", $e->getMessage(), 'says what needed it');
            self::assertStringContainsString($dep->title(), $e->getMessage(), 'and what is missing');
            self::assertStringContainsString(
                'composer require ' . $dep->package(),
                $e->getMessage(),
                'and how to fix it — otherwise the reader has to guess the package name',
            );
        }
    }

    public function test_the_answers_are_independent(): void
    {
        $this->without(Dep::Redis);

        self::assertFalse(DepSupport::has(Dep::Redis));
        self::assertTrue(DepSupport::has(Dep::Ppa), 'one missing package must not hide another');
    }

    public function test_health_reports_no_pools_with_neither_package(): void
    {
        $this->without(Dep::Ppa, Dep::Redis);

        self::assertSame(['status' => 'up', 'pools' => []], new HealthIndicator()->pools());
    }

    public function test_health_skips_the_redis_section_without_the_package(): void
    {
        $this->without(Dep::Redis);

        $redis = new ReflectionMethod(HealthIndicator::class, 'redisHealth')
            ->invoke(new HealthIndicator(), __DIR__);

        self::assertSame(['status' => 'up', 'details' => []], $redis, 'nothing to probe, and it says so');
    }

    public function test_the_answer_is_cached_but_forgettable(): void
    {
        $this->without(Dep::Ppa);
        self::assertFalse(DepSupport::has(Dep::Ppa), 'cached');

        DepSupport::forget();

        self::assertTrue(DepSupport::has(Dep::Ppa), 'and re-read after forget()');
    }
}
