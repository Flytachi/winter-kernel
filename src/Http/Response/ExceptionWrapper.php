<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

use Flytachi\Winter\Kernel\Factory\Mapping;

final class ExceptionWrapper
{
    private function __construct()
    {
    }

    public static function wrap(\Throwable $throwable): ResponseExceptionInterface
    {
        $advices = self::advices();
        if (empty($advices)) {
            return new \Flytachi\Winter\Kernel\Stereotype\Output\ResponseException($throwable);
        }

        foreach ($advices as $advice) {
            if (empty($advice['exceptions'])) {
                return new $advice['className']($throwable);
            }
            if (in_array($throwable::class, $advice['exceptions'], true)) {
                return new $advice['className']($throwable);
            }
        }

        return new \Flytachi\Winter\Kernel\Stereotype\Output\ResponseException($throwable);
    }

    private static function advices(): array
    {
        // information
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
