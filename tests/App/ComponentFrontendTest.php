<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\App;

use Flytachi\Winter\Kernel\App\Component;
use Flytachi\Winter\Kernel\App\ComponentKind;
use Flytachi\Winter\Kernel\App\Components;
use Flytachi\Winter\Kernel\App\FrontBuild;
use Flytachi\Winter\Kernel\App\Frontend;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\ServiceImage;
use PHPUnit\Framework\TestCase;

/** A frontend as a component: what the supervisor runs for it, and what Components keeps. */
final class ComponentFrontendTest extends TestCase
{
    public function test_a_frontend_component_takes_its_sites_name_and_defaults(): void
    {
        $site = new FrontSite('admin', '/build/admin');
        $component = Component::frontend($site);

        self::assertSame(ComponentKind::Frontend, $component->kind);
        self::assertSame('admin', $component->name());
        self::assertFalse($component->critical);
        self::assertSame(Priority::Low, $component->priority);
        self::assertSame(0, $component->retries);
        self::assertSame(0, $component->startTimeout);
        self::assertSame($site, $component->site);
        self::assertSame($site, $component->at('127.0.0.1', 9000)->site, 'kept when bound elsewhere');
    }

    public function test_a_critical_frontend_stays_critical(): void
    {
        $component = Component::frontend(new FrontSite('admin', '/build/admin', critical: true, priority: Priority::High));

        self::assertTrue($component->critical);
        self::assertSame(Priority::High, $component->priority);
    }

    public function test_components_keep_the_frontend_and_its_component(): void
    {
        $frontend = new class implements Frontend {
            public function site(): FrontSite
            {
                return new FrontSite('frontend', '/build/frontend');
            }

            public function image(): ServiceImage
            {
                return new ServiceImage();
            }

            public function build(): ?FrontBuild
            {
                return null;
            }

            public function hints(): array
            {
                return [];
            }
        };
        $components = new Components('/project');
        $components->frontend($frontend);

        self::assertSame([$frontend], $components->frontends());
        self::assertCount(1, $components->all());
        self::assertSame(ComponentKind::Frontend, $components->all()[0]->kind);
        self::assertSame('frontend', $components->all()[0]->name());
    }
}
