<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Stereotype\Output;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Kernel\Http\Response\ViewBase;

class View extends ViewBase
{
    public static function render(
        ?string $templateName,
        string $resourceName,
        array $data = [],
        HttpCode $httpCode = HttpCode::OK
    ): static {
        return new static($templateName, $resourceName, $data, $httpCode);
    }

    public static function view(string $resourceName, array $data = [], HttpCode $httpCode = HttpCode::OK): static
    {
        return new static(null, $resourceName, $data, $httpCode);
    }
}
