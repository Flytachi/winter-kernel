<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use Flytachi\Winter\Kernel\Exception\ClientError;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
class RouteNotFound extends HttpRouter implements ActuatorItemInterface
{
    protected function route(array $input, bool $isDevelop = false): never
    {
        $render = new Rendering();
        $render->setResource(new ClientError(
            "{$_SERVER['REQUEST_METHOD']} '{$input['path']}' url not found",
            HttpCode::NOT_FOUND->value
        ));
        $render->render();
    }
}
