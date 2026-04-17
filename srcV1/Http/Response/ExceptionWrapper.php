<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\Factory\Mapping;
use Flytachi\Winter\Kernel\Kernel;
use Flytachi\Winter\Kernel\Stereotype\Output\ResponseException;
use JetBrains\PhpStorm\Deprecated;

#[Deprecated]
final class ExceptionWrapper
{
    private function __construct()
    {
    }

    public static function wrap(\Throwable $throwable): ResponseExceptionInterface
    {
        $advices = self::advices();
        if (empty($advices)) {
            return new ResponseException($throwable);
        }

        foreach ($advices as $advice) {
            if (empty($advice['exceptions'])) {
                return new $advice['className']($throwable);
            }
            if (in_array($throwable::class, $advice['exceptions'], true)) {
                return new $advice['className']($throwable);
            }
        }

        return new ResponseException($throwable);
    }

    private static function advices(): array
    {
        $isDevelop = (bool) env('DEBUG', false);
        $path = Kernel::$pathStorageVolatile . '/exception-wrapper.php';
        if ($isDevelop) {
            if (file_exists($path)) {
                unlink($path);
            }
            return self::generateAdvices();
        } else {
            if (file_exists($path)) {
                return require $path;
            } else {
                $advices = self::generateAdvices();
                $mapString = var_export(json_decode(json_encode($advices), true), true);
                $fileData = "<?php" . PHP_EOL . PHP_EOL;
                $fileData .= "/**" . PHP_EOL . " * Exception wrapper configurations"
                    . PHP_EOL . " * - Created on: " . date(DATE_RFC822)
                    . PHP_EOL . " * - Version: 1.0"
                    . PHP_EOL . " */" . PHP_EOL . PHP_EOL
                    . "return $mapString;";
                file_put_contents($path, $fileData);
                if (function_exists('opcache_invalidate')) {
                    try {
                        opcache_invalidate($path, true);
                    } catch (\Throwable $e) {
                    }
                }
                return $advices;
            }
        }
    }

    private static function generateAdvices(): array
    {
        $resources = Mapping::scanProjectFiles();
        $resources = Mapping::scanRefClasses($resources, ResponseExceptionInterface::class);
        if (empty($resources)) {
            return [];
        }

        $advices = [];
        foreach ($resources as $resource) {
            $adviceException = $resource->getAttributes(AdviceException::class);
            if (empty($adviceException)) {
                continue;
            }
            $adviceException = $adviceException[0]->newInstance();
            $data = [
                'className' => $resource->getName(),
                'exceptions' => $adviceException->exceptionClassNames,
            ];
            if (empty($adviceException->exceptionClassNames)) {
                $advices[] = $data;
            } else {
                array_unshift($advices, $data);
            }
        }

        return $advices;
    }
}
