<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Interface\ActuatorItemInterface;
use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Kernel\Exception\ClientError;
use Flytachi\Winter\Kernel\Factory\Mapping;
use Flytachi\Winter\Kernel\Factory\Middleware\Cors\AccessControl;
use Flytachi\Winter\Kernel\Factory\Plugin;
use Flytachi\Winter\Kernel\Kernel;

class PluginRouter extends HttpRouter implements ActuatorItemInterface
{
    use RoutingTrait;

    private string $folderMapping;

    public function __construct()
    {
        parent::__construct();
        $this->folderMapping = Kernel::$pathStorageVolatile . '/plugin-mapping/';
    }

    protected function route(array $input, bool $isDevelop = false): void
    {
        $usePluginPrefix = null;
        $usePluginPath = null;
        foreach (Plugin::getPlugins() as $pluginPrefix => $pluginPath) {
            if (
                str_starts_with($input['path'], "/{$pluginPrefix}/")
                || $input['path'] === "/{$pluginPrefix}"
            ) {
                $input['path'] = ltrim(substr($input['path'], strlen("/{$pluginPrefix}")), '/');
                $usePluginPrefix = $pluginPrefix;
                $usePluginPath = $pluginPath;
                break;
            }
        }

        if (!$usePluginPath) {
            return;
        }

        $render = new Rendering();
        try {
            // registration
            $this->registrar($usePluginPath, $isDevelop, $usePluginPrefix);
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

    private function registrar(string $pluginPath, bool $isDevelop, string $prefix): void
    {
        if ($isDevelop) {
            if (file_exists($this->folderMapping . $prefix . '.php')) {
                unlink($this->folderMapping . $prefix . '.php');
            }
            $declaration = Mapping::scanningDeclaration($pluginPath);
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
            if (!file_exists($this->folderMapping . $prefix . '.php')) {
                $this->generateMappingRoutes($pluginPath, $prefix);
            } else {
                $this->routes = require $this->folderMapping . $prefix . '.php';
            }
        }
    }

    final public function generateMappingRoutes(string $pluginPath, string $prefix): void
    {
        if (!is_dir($this->folderMapping)) {
            mkdir($this->folderMapping, 0777, true);
        }
        $declaration = Mapping::scanningDeclaration($pluginPath);
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
        $fileData .= "/**" . PHP_EOL . " * Mapping $prefix configurations"
            . PHP_EOL . " * - Created on: " . date(DATE_RFC822)
            . PHP_EOL . " * - Version: 2.0"
            . PHP_EOL . " */" . PHP_EOL . PHP_EOL
            . "return $mapString;";
        file_put_contents($this->folderMapping . $prefix . '.php', $fileData);
        if (function_exists('opcache_reset')) {
            try {
                opcache_reset();
            } catch (\Throwable $e) {
            }
        }
    }

    public function getFolderMapping(): string
    {
        return $this->folderMapping;
    }
}
