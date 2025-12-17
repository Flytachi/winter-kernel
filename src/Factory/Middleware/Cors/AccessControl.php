<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Middleware\Cors;

use Flytachi\Winter\Base\Method;

final class AccessControl
{
    use AccessControlTrait;

    protected array $origin = [];
    protected array $methods = [Method::OPTIONS->name];
    protected array $allowHeaders = [];
    protected array $exposeHeaders = [];
    protected bool $credentials = false;
    protected int $maxAge = 0;
    protected array $vary = [];

    public static function processed(array $options): never
    {
        $router = new self();
        foreach ($options['actions'] as $httpMethod => $action) {
            $router->pushMethod($httpMethod);
            foreach ($action['middlewares'] as $middlewareClass) {
                self::configureRouter($router, $middlewareClass);
            }
        }
        if (isset($options['defaultAction'])) {
            $router->pushMethod(Method::GET->name);
            $router->pushMethod(Method::POST->name);
            $router->pushMethod(Method::DELETE->name);
            $router->pushMethod(Method::PATCH->name);
            $router->pushMethod(Method::PUT->name);
            foreach ($options['defaultAction']['middlewares'] as $middlewareClass) {
                self::configureRouter($router, $middlewareClass);
            }
        }
        $router->using();
        exit;
    }

    final protected function pushOrigin(array $origins): void
    {
        foreach ($origins as $origin) {
            if (!in_array($origin, $this->origin)) {
                $this->origin[] = $origin;
            }
        }
    }

    final protected function pushMethod(string $httpMethod): void
    {
        if (!in_array($httpMethod, $this->methods)) {
            $this->methods[] = $httpMethod;
        }
    }

    final protected function pushAllowHeaders(array $headers): void
    {
        foreach ($headers as $header) {
            if (!in_array($header, $this->allowHeaders)) {
                $this->allowHeaders[] = $header;
            }
        }
    }

    final protected function pushExposeHeaders(array $headers): void
    {
        foreach ($headers as $header) {
            if (!in_array($header, $this->exposeHeaders)) {
                $this->exposeHeaders[] = $header;
            }
        }
    }

    final protected function pushCredentials(bool $credentials): void
    {
        if ($credentials) {
            $this->credentials = true;
        }
    }

    final protected function pushMaxAge(int $maxAge): void
    {
        if ($maxAge > 0) {
            if ($maxAge > $this->maxAge) {
                $this->maxAge = $maxAge;
            }
        }
    }

    final protected function pushVary(array $varies): void
    {
        foreach ($varies as $vary) {
            if (!in_array($vary, $this->vary)) {
                $this->vary[] = $vary;
            }
        }
    }

    /**
     * @param AccessControl &$router
     * @param mixed $middlewareClass
     * @return void
     */
    private static function configureRouter(AccessControl &$router, string $middlewareClass): void
    {
        if (
            $middlewareClass == AccessControlMiddleware::class ||
            is_subclass_of($middlewareClass, AccessControlMiddleware::class)
        ) {
            $data = $middlewareClass::passport();
            $router->pushOrigin($data['origin']);
            $router->pushAllowHeaders($data['allowHeaders']);
            $router->pushExposeHeaders($data['exposeHeaders']);
            $router->pushCredentials($data['credentials']);
            $router->pushMaxAge($data['maxAge']);
            $router->pushVary($data['vary']);
        }
    }

    final protected function using(): void
    {
        header("HTTP/1.1 200 OK");
        if (!empty($this->methods)) {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->methods));
        }
        $this->useHeaders();
    }
}
