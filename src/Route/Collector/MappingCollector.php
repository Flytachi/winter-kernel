<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Route\Collector;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\Kernel\Route\Annotation\AbstractMapping;
use Flytachi\Winter\Kernel\Route\Annotation\CrossOrigin;
use Flytachi\Winter\Kernel\Route\Annotation\RequestMapping;
use Flytachi\Winter\Kernel\Route\Router;
use Flytachi\Winter\Kernel\Http\Stereotype\ControllerInterface;
use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

final readonly class MappingCollector implements CollectorInterface
{
    public function __construct(
        private Router $router,
        private string $prefix = '',
    ) {
    }

    public function collect(string $class, ReflectionClass $ref): void
    {
        if (!$ref->implementsInterface(ControllerInterface::class)) {
            return;
        }

        $classPrefix = '';
        $classAttrs  = $ref->getAttributes(RequestMapping::class);
        if (!empty($classAttrs)) {
            $classPrefix = rtrim($classAttrs[0]->newInstance()->getUrl(), '/');
        }

        $classMiddlewares = $this->collectMiddlewares(
            $ref->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF)
        );
        $classCors = $this->collectCrossOrigin($ref->getAttributes(CrossOrigin::class));

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $attrs = $method->getAttributes(AbstractMapping::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attrs as $attr) {
                /** @var AbstractMapping $mapping */
                $mapping    = $attr->newInstance();
                $httpMethod = $mapping->getMethod();

                $url = $classPrefix !== '' && $mapping->getUrl() !== ''
                    ? $classPrefix . '/' . $mapping->getUrl()
                    : ($classPrefix ?: $mapping->getUrl());

                $url = $this->prefix . '/' . ltrim($url, '/');

                $handler = [$ref->getName(), $method->getName()];

                $methodMiddlewares = $this->collectMiddlewares(
                    $method->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF)
                );
                $middlewares = array_merge($classMiddlewares, $methodMiddlewares);

                $cors = $this->collectCrossOrigin($method->getAttributes(CrossOrigin::class)) ?? $classCors;

                if ($httpMethod !== null) {
                    $this->router->add($httpMethod, $url, $handler, $middlewares, $cors);
                } else {
                    foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
                        $this->router->add($m, $url, $handler, $middlewares, $cors);
                    }
                }
            }
        }
    }

    /** @param ReflectionAttribute[] $attrs */
    private function collectMiddlewares(array $attrs): array
    {
        $result = [];
        foreach ($attrs as $attr) {
            $result[] = ['class' => $attr->getName(), 'args' => $attr->getArguments()];
        }
        return $result;
    }

    /** @param ReflectionAttribute[] $attrs */
    private function collectCrossOrigin(array $attrs): ?array
    {
        if (empty($attrs)) {
            return null;
        }
        /** @var CrossOrigin $inst */
        $inst = $attrs[0]->newInstance();
        return [
            'origins'       => $inst->origins,
            'allowHeaders'  => $inst->allowHeaders,
            'exposeHeaders' => $inst->exposeHeaders,
            'credentials'   => $inst->credentials,
            'maxAge'        => $inst->maxAge,
            'vary'          => $inst->vary,
        ];
    }
}
