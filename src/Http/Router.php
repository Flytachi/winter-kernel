<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\Header;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use Flytachi\Winter\Base\Interface\Stereotype;
use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Kernel\Exception\ClientError;
use Flytachi\Winter\Kernel\Factory\Mapping;
use Flytachi\Winter\Kernel\Factory\Middleware\Cors\AccessControl;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Stereotype\ControllerInterface;

final class Router extends Stereotype implements ActuatorItemInterface
{
    /**
     * An array to store registered routes in a tree structure.
     *
     * @var array
     */
    private array $routes = [];

    final public function run(): void
    {
        Header::setHeaders();
        $this->route((bool) env('DEBUG', false));
    }

    private function route(bool $isDevelop = false): void
    {
        $this->logger->debug(
            'route: ' . $_SERVER['REQUEST_METHOD']
            . ' ' . $_SERVER['REQUEST_URI']
            . ' IP: ' . Header::getIpAddress()
        );

        $render = new Rendering();
        try {
            // registration
            $this->registrar($isDevelop);

            $input = parseUrlDetail($_SERVER['REQUEST_URI']);
            $_GET = $input['query'];

            $resolve = $this->resolveActions($input['path']);
            if (!$resolve) {
                throw new ClientError(
                    "{$_SERVER['REQUEST_METHOD']} '{$input['path']}' url not found",
                    HttpCode::NOT_FOUND->value
                );
            }

            // options
            if ($_SERVER['REQUEST_METHOD'] == Method::OPTIONS->name) {
                AccessControl::processed($resolve['options']);
            }

            $resolve = $this->resolveActionSelect($resolve, $_SERVER['REQUEST_METHOD']);
            if (!$resolve) {
                throw new ClientError(
                    "{$_SERVER['REQUEST_METHOD']} '{$input['path']}' url not found",
                    HttpCode::NOT_FOUND->value
                );
            }
            $result = $this->callResolveAction($resolve['action'], $resolve['params'], $resolve['url'] ?? '');
        } catch (\Throwable $result) {
        } finally {
            $render->setResource($result);
        }

        $render->render();
    }

    /**
     * Resolves a given URL and HTTP method to a registered route.
     *
     * This method searches the registered routes for a match to the provided URL and HTTP method.
     * If a match is found, it returns an array containing the associated controller action and any dynamic parameters.
     * If no match is found, it returns null.
     *
     * @param string $url The requested URL to resolve.
     * @return array|null Returns an array with the action and
     * parameters if a route is found, or null if no route matches.
     */
    final public function resolveActions(string $url): ?array
    {
        $node = $this->routes;
        $params = [];
        $parts = explode('/', trim($url, '/'));

        // Traverse the route tree to find a match
        foreach ($parts as $part) {
            if (isset($node[$part])) {
                $node = $node[$part];
            } elseif (isset($node['{param}'])) {
                $node = $node['{param}'];
                $params[] = $part;
            } else {
                return null; // No matching route found
            }
        }

        return ['options' => $node, 'params' => $params];
    }

    final public function resolveActionSelect(array $resolve, string $httpMethod): ?array
    {
        if (isset($resolve['options']['actions'][$httpMethod])) {
            return [
                'action' => $resolve['options']['actions'][$httpMethod],
                'params' => $resolve['params']
            ];
        }
        if (isset($resolve['options']['defaultAction'])) {
            return ['action' => $resolve['options']['defaultAction'], 'params' => $resolve['params']];
        }

        return null;
    }

    private function registrar(bool $isDevelop): void
    {
        if ($isDevelop) {
            if (file_exists(Kernel::$pathFileMapping)) {
                unlink(Kernel::$pathFileMapping);
            }
            $declaration = Mapping::scanningDeclaration();
            foreach ($declaration->getChildren() as $item) {
                $this->request(
                    $item->getUrl(),
                    $item->getClassName(),
                    $item->getClassMethod(),
                    $item->getMiddlewareClassNames(),
                    $item->getMethod(),
                    $item->getMethodArgs()
                );
            }
        } else {
            if (!file_exists(Kernel::$pathFileMapping)) {
                $this->generateMappingRoutes();
            } else {
                $this->routes = require Kernel::$pathFileMapping;
            }
        }
    }

    /**
     * Registers a route with the router.
     *
     * This method allows you to define a route, associate it with a controller class and method,
     * and optionally specify an HTTP method. The route can include dynamic parameters (e.g., `/user/{id}`).
     *
     * @param string $route The URL route pattern (e.g., "/user/{id}").
     * @param string $class The controller class to handle the route.
     * @param string $classMethod The method within the controller class to call (defaults to 'index').
     * @param array $middlewares
     * @param string|null $method The HTTP method for the route (e.g., 'GET', 'POST', ...).
     * If null, the route will be treated as a default action.
     * @param array $classMethodArgs
     * @return void
     * @throws RouterException If the route is already registered with the same HTTP method or as a default action.
     */
    private function request(
        string $route,
        string $class,
        string $classMethod = 'index',
        array $middlewares = [],
        ?string $method = null,
        array $classMethodArgs = []
    ): void {
        // Normalize the URL by trimming slashes
        $route = trim($route, '/');
        $parts = explode('/', $route);

        // Build the route tree
        $node = &$this->routes;
        foreach ($parts as $part) {
            $isParam = preg_match('/^\{[a-zA-Z_][a-zA-Z0-9_]*}$/', $part) === 1;
            $key = $isParam ? '{param}' : $part;

            if (!isset($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }

        // Register middlewares
        if (!empty($middlewares)) {
            $duplicates = array_diff_assoc($middlewares, array_unique($middlewares));
            if (!empty($duplicates)) {
                $duplicatesList = implode(', ', $duplicates);
                throw new RouterException("Duplicate Middleware found: [{$duplicatesList}].");
            }
        }

        // Register the route with the specified HTTP method or as a default action
        if (!empty($method)) {
            if (isset($node['actions'][$method])) {
                throw new RouterException("Route '$route' with method '$method' is already registered.");
            }
            $node['actions'][$method] = [
                'class' => $class,
                'method' => $classMethod,
                'methodArgs' => $classMethodArgs,
                'middlewares' => $middlewares
            ];
        } else {
            if (isset($node['defaultAction'])) {
                throw new RouterException("Route '$route' (default) is already registered.");
            }
            $node['defaultAction'] = [
                'class' => $class,
                'method' => $classMethod,
                'methodArgs' => $classMethodArgs,
                'middlewares' => $middlewares
            ];
        }
    }

    final public function generateMappingRoutes(): void
    {
        $declaration = Mapping::scanningDeclaration();
        foreach ($declaration->getChildren() as $item) {
            $this->request(
                $item->getUrl(),
                $item->getClassName(),
                $item->getClassMethod(),
                $item->getMiddlewareClassNames(),
                $item->getMethod(),
                $item->getMethodArgs(),
            );
        }
        $mapString = var_export(json_decode(json_encode($this->routes), true), true);
        $fileData = "<?php" . PHP_EOL . PHP_EOL;
        $fileData .= "/**" . PHP_EOL . " * Mapping configurations"
            . PHP_EOL . " * - Created on: " . date(DATE_RFC822)
            . PHP_EOL . " * - Version: 1.5"
            . PHP_EOL . " */" . PHP_EOL . PHP_EOL
            . "return {$mapString};";
        file_put_contents(Kernel::$pathFileMapping, $fileData);
        if (function_exists('opcache_reset')) {
            try {
                opcache_reset();
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * @param array{class: class-string<ControllerInterface>, method: string} $action
     * @param array<int, string> $params
     * @param string $stringUrl
     * @return mixed
     * @throws RouterException|ClientError
     */
    final protected function callResolveAction(array $action, array $params = [], string $stringUrl = ''): mixed
    {
        $controller = new $action['class']();
        $methods = get_class_methods($controller);

        if (!in_array($action['method'], $methods)) {
            throw new RouterException(
                "{$_SERVER['REQUEST_METHOD']} '{$stringUrl}' url realization '{$action['method']}' not found"
            );
        }

        if (isset($action['methodArgs'])) {
            foreach ($action['methodArgs'] as $key => $value) {
                if (!isset($params[$key])) {
                    continue;
                }

                if (!empty($value['typeInfo']) && !empty($value['typeInfo']['backing'])) {
                    if ($value['typeInfo']['backing'] === 'int' && !is_numeric($params[$key])) {
                        throw new ClientError(
                            "Argument '{$params[$key]}' does not correspond to type int",
                            HttpCode::BAD_REQUEST->value
                        );
                    }
                    settype($params[$key], $value['typeInfo']['backing']);
                    $params[$value['name']] = $value['typeInfo']['name']::from($params[$key]);
                } else {
                    $params[$value['name']] = $params[$key];
                }

                unset($params[$key]);
            }
        }

        try {
            $middlewares = [];
            foreach ($action['middlewares'] as $middlewareName) {
                $middleware = new $middlewareName();
                $middleware->optionBefore();
                $middlewares[] = $middleware;
            }

            $result = call_user_func_array([$controller, $action['method']], $params);

            foreach ($middlewares as $middleware) {
                $result = $middleware->optionAfter($result);
            }
            return $result;
        } catch (\ArgumentCountError | \TypeError $exception) {
            $trace = $exception->getTrace();

            if (
                isset($trace[1]['function'], $trace[1]['file']) &&
                $trace[1]['function'] === 'call_user_func_array' &&
                $trace[1]['file'] === __FILE__
            ) {
                $temp = $controller::class . "::" . $action['method'] . '()';
                throw new ClientError(
                    str_replace($temp, '', $exception->getMessage()),
                    HttpCode::BAD_REQUEST->value
                );
            } else {
                throw $exception;
            }
        }
    }
}
