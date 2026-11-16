<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\FrontApp;

use Flytachi\Winter\Kernel\Http\Stereotype\Controller;
use Flytachi\Winter\Kernel\Route\Annotation\GetMapping;

/** The API beside the frontends. */
final class FrontController extends Controller
{
    #[GetMapping('/api/ping')]
    public function ping(): string
    {
        return 'pong';
    }

    #[GetMapping('/admin/users')]
    public function users(): string
    {
        return 'users';
    }

    #[\Flytachi\Winter\Kernel\Route\Annotation\PostMapping('/admin/upload')]
    public function upload(\Flytachi\Winter\Kernel\Http\Contracts\HttpRequest $request): string
    {
        return (string) strlen($request->getRawBody());
    }

    #[GetMapping('/about')]
    public function about(): string
    {
        return 'the controller about';
    }

    #[GetMapping('/taken')]
    public function taken(): string
    {
        return 'the controller';
    }
}
