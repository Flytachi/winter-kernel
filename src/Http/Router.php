<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Kernel\Exception\ClientError;
use Flytachi\Winter\Kernel\Factory\Mapping;
use Flytachi\Winter\Kernel\Factory\Middleware\Cors\AccessControl;
use Flytachi\Winter\Kernel\Kernel;

final class Router extends HttpRouter implements ActuatorItemInterface
{
    use RoutingTrait;

    private string $pathMapping;

    public function __construct()
    {
        parent::__construct();
        $this->pathMapping = Kernel::$pathStorageVolatile . '/mapping.php';
    }

    protected function route(array $input, bool $isDevelop = false): void
    {
        $render = new Rendering();
        try {
            // registration
            $this->registrar($isDevelop);
            $_GET = $input['query'];

            $resolve = $this->resolveActions($input['path']);
            if (!$resolve) {
                return;
            }

            // options
            if ($_SERVER['REQUEST_METHOD'] == Method::OPTIONS->name) {
                AccessControl::processed($resolve['options']);
            }

            $resolve = $this->resolveActionSelect($resolve, $_SERVER['REQUEST_METHOD']);
            if (!$resolve) {
                return;
            }
            $result = $this->callResolveAction($resolve['action'], $resolve['params'], $resolve['url'] ?? '');
        } catch (\Throwable $result) {
        } finally {
            $render->setResource($result ?? null);
        }

        $render->render();
    }

    private function registrar(bool $isDevelop): void
    {
        if ($isDevelop) {
            if (file_exists($this->pathMapping)) {
                unlink($this->pathMapping);
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
            if (!file_exists($this->pathMapping)) {
                $this->generateMappingRoutes();
            } else {
                $this->routes = require $this->pathMapping;
            }
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
            . PHP_EOL . " * - Version: 2.0"
            . PHP_EOL . " */" . PHP_EOL . PHP_EOL
            . "return $mapString;";
        file_put_contents($this->pathMapping, $fileData);
        if (function_exists('opcache_invalidate')) {
            try {
                opcache_invalidate($this->pathMapping, true);
            } catch (\Throwable $e) {
            }
        }
    }

    public function getPathMapping(): string
    {
        return $this->pathMapping;
    }
}
