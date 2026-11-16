<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp;

use Flytachi\Winter\Kernel\App\FrontBuild;
use Flytachi\Winter\Kernel\App\Frontend;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\App\ServiceImage;

/** A frontend that is only its site: what a package would hand the kernel. */
final readonly class TestFrontend implements Frontend
{
    public function __construct(private FrontSite $site, private ?FrontBuild $build = null)
    {
    }

    public function site(): FrontSite
    {
        return $this->site;
    }

    public function image(): ServiceImage
    {
        return new ServiceImage();
    }

    public function build(): ?FrontBuild
    {
        return $this->build;
    }
}
