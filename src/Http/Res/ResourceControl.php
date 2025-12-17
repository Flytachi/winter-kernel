<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Res;

use Flytachi\Winter\Kernel\Kernel;

abstract class ResourceControl
{
    final public static function import(string $resourceName): void
    {
        include Kernel::$pathResource . "/$resourceName.php";
        ResourceTree::registerAdditionResource(Kernel::$pathResource . "/$resourceName.php");
    }

    final public static function content(): void
    {
        ResourceTree::importResource();
    }

    final public static function getData(?string $valueKey = null): mixed
    {
        return ResourceTree::getResourceData($valueKey);
    }
}
