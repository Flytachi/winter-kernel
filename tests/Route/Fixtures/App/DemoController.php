<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Tests\Route\Fixtures\App;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\Kernel\Route\Annotation\GetMapping;
use Flytachi\Winter\Kernel\Route\Annotation\PostMapping;
use Flytachi\Winter\Kernel\Route\Annotation\RequestMapping;
use Flytachi\Winter\Kernel\Http\Stereotype\Controller;

/**
 * The application under test: an ordinary controller, declared the way a coder would.
 *
 * Nothing here is registered by hand — the scan has to find the class, the container
 * has to build it with its dependency, and the resolver has to fill each argument.
 */
#[RequestMapping('/demo')]
final class DemoController extends Controller
{
    #[Autowired]
    private GreetingService $greetings;

    #[GetMapping('/ping')]
    public function ping(): string
    {
        return 'pong';
    }

    /** The class-level prefix must combine with the method path. */
    #[GetMapping('/hello/{name}')]
    public function hello(#[PathVariable] string $name): array
    {
        return ['message' => $this->greetings->greet($name)];
    }

    /** Query parameters and their defaults. */
    #[GetMapping('/search')]
    public function search(#[RequestParam] string $q, #[RequestParam] int $limit = 10): array
    {
        return ['q' => $q, 'limit' => $limit];
    }

    /** The raw request object is injectable by type. */
    #[GetMapping('/echo')]
    public function echoMethod(HttpRequest $request): array
    {
        return ['method' => $request->getMethod(), 'uri' => $request->getUri()];
    }

    #[PostMapping('/items')]
    public function create(): array
    {
        return ['created' => true];
    }
}
