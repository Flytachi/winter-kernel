<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Process\Integration;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\FrontSite;
use PHPUnit\Framework\Attributes\Group;

/**
 * A frontend under the supervisor: no process of its own, up while its build is there,
 * failed — the rest running on — while it is not, and up again once it appears.
 */
#[Group('integration')]
final class SupervisorFrontendIntegrationTest extends IntegrationCase
{
    use SupervisesComponents;

    private function site(bool $built): FrontSite
    {
        $root = $this->storage . '/front';
        @mkdir($root, 0777, true);
        if ($built) {
            file_put_contents($root . '/200.html', '<html></html>');
        }
        return new FrontSite('frontend', $root);
    }

    public function test_a_built_frontend_is_up_without_a_process(): void
    {
        $supervisor = $this->supervise([
            Component::frontend($this->site(built: true)),
            Component::command(['sleep', '30'], 'sleeper', critical: false),
        ]);

        self::assertTrue($this->waitLogged('frontend is up.'));
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_a_missing_build_is_failed_and_the_rest_runs_on(): void
    {
        $supervisor = $this->supervise([
            Component::frontend($this->site(built: false)),
            Component::command(['sleep', '30'], 'sleeper', critical: false),
        ]);

        self::assertTrue($this->waitLogged('frontend failed: not built'));
        self::assertTrue($this->waitLogged('sleeper is up.'));
        self::assertStringContainsString('It is not critical', $this->logged());
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_a_build_that_appears_later_brings_it_up(): void
    {
        $site = $this->site(built: false);
        $supervisor = $this->supervise([
            Component::frontend($site),
            Component::command(['sleep', '30'], 'sleeper', critical: false),
        ]);
        self::assertTrue($this->waitLogged('frontend failed: not built'));

        file_put_contents($site->marker(), '<html></html>');

        self::assertTrue($this->waitLogged('frontend is up.', 10.0));
        posix_kill($supervisor, SIGTERM);
        self::assertSame(0, $this->exitCode($supervisor));
    }

    public function test_a_critical_frontend_without_a_build_stops_the_application(): void
    {
        $root = $this->storage . '/none';
        $supervisor = $this->supervise([
            Component::frontend(new FrontSite('frontend', $root, critical: true)),
            Component::command(['sleep', '30'], 'sleeper', critical: false),
        ]);

        self::assertSame(1, $this->exitCode($supervisor));
        self::assertStringContainsString('frontend failed: not built', $this->announced());
    }
}
