<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Middleware\Cors;

use Flytachi\Winter\Kernel\Factory\Middleware\AbstractMiddleware;
use Flytachi\Winter\Kernel\Factory\Middleware\MiddlewareInterface;

abstract class AccessControlMiddleware extends AbstractMiddleware implements MiddlewareInterface
{
    use AccessControlTrait;

    protected array $origin = [];
    protected array $allowHeaders = [];
    protected array $exposeHeaders = [];
    protected bool $credentials = false;
    protected int $maxAge = 0;
    protected array $vary = [];

    final public static function passport(): array
    {
        $self = new static();
        return [
            'origin' => $self->origin,
            'allowHeaders' => $self->allowHeaders,
            'exposeHeaders' => $self->exposeHeaders,
            'credentials' => $self->credentials,
            'maxAge' => $self->maxAge,
            'vary' => $self->vary,
        ];
    }

    public function optionBefore(): void
    {
        header('Access-Control-Allow-Methods: ' . $_SERVER['REQUEST_METHOD']);
        $this->useHeaders();
    }
}
