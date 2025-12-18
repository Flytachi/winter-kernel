<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Make extends Cmd
{
    public static string $title = "command for creating templates";
    private string $createPath;
    private string $templatePath;

    public function handle(): void
    {
        self::printTitle("Make", 32);
        $this->templatePath = dirname(__DIR__) . '/Template/Make';
        array_shift($this->args['arguments']);

        if (count($this->args['arguments']) == 0) {
            self::printMessage("Enter the names of the generated templates");
            self::print("Example: extra make example");
            self::print("Help: extra make [--help or -h]");
        } elseif (!count($this->args['flags'])) {
            self::printMessage("Specify template types");
            self::print("Example: extra make -asrm example");
            self::print("Help: extra make [--help or -h]");
        } else {
            $this->resolution();
        }

        self::printTitle("Make", 32);
    }

    private function resolution(): void
    {
        foreach ($this->args['arguments'] as $templateName) {
            $templateName = str_replace('.', '/', $templateName);
            if (str_starts_with($templateName, '/')) {
                $this->createPath = Kernel::$pathRoot;
            } else {
                $this->createPath = Kernel::$pathMain;
            }
            // ---
            if (in_array('a', $this->args['flags'])) {
                $this->createRestController($templateName);
            }
            if (in_array('c', $this->args['flags'])) {
                $this->createController($templateName);
            }
            if (in_array('s', $this->args['flags'])) {
                $this->createService($templateName);
            }
            if (in_array('l', $this->args['flags'])) {
                $this->createMiddleware($templateName);
            }
            if (in_array('r', $this->args['flags'])) {
                $this->createRepository($templateName);
            }
            if (in_array('t', $this->args['flags'])) {
                $this->createStore($templateName);
            }
            if (in_array('m', $this->args['flags'])) {
                $this->createModel($templateName);
            }
            if (in_array('d', $this->args['flags'])) {
                $this->createDto($templateName);
            }
            if (in_array('q', $this->args['flags'])) {
                $this->createRequest($templateName);
            }
            if (in_array('e', $this->args['flags'])) {
                $this->createResponse($templateName);
            }
            if (in_array('j', $this->args['flags'])) {
                $this->createJob($templateName);
            }
            if (in_array('p', $this->args['flags'])) {
                $this->createProcess($templateName);
            }
            if (in_array('u', $this->args['flags'])) {
                $this->createCluster($templateName);
            }
            if (in_array('w', $this->args['flags'])) {
                $this->createWebSocket($templateName);
            }
            if (in_array('D', $this->args['flags'])) {
                $this->createConfig($templateName);
            }
            if (in_array('R', $this->args['flags'])) {
                $this->createRedisConfig($templateName);
            }
            if (in_array('n', $this->args['flags'])) {
                $this->createCmd($templateName);
            }
        }
    }

    private function createRestController(string $name): void
    {
        $info = $this->getInfo($name, 'Controller', 'RestControllerTemplate');
        $this->smartInfo(
            $info,
            'Rests',
            'Rest',
            'Controllers/Rests',
            'Controllers/Rest',
            'Controller/Rests',
            'Controller/Rest',
            'Controllers',
            'Controller'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $code = str_replace("__shortName__", lcfirst(str_replace('Controller', '', $info['className'])), $code);
        $this->createFile($info['className'], $info['path'], $code, 'rest');
    }

    private function createController(string $name): void
    {
        $info = $this->getInfo($name, 'Controller', 'ControllerTemplate');
        $this->smartInfo(
            $info,
            'Webs',
            'Web',
            'Controllers/Webs',
            'Controllers/Web',
            'Controller/Webs',
            'Controller/Web',
            'Controllers',
            'Controller'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $code = str_replace("__shortName__", lcfirst(str_replace('Controller', '', $info['className'])), $code);
        $this->createFile($info['className'], $info['path'], $code, 'controller');
    }

    private function createService(string $name): void
    {
        $info = $this->getInfo($name, 'Service', 'ServiceTemplate');
        $this->smartInfo($info, 'Services', 'Service');
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'service');
    }

    private function createMiddleware(string $name): void
    {
        $info = $this->getInfo($name, 'Middleware', 'MiddlewareTemplate');
        $this->smartInfo(
            $info,
            'Controllers/Middlewares',
            'Controllers/Middleware',
            'Controller/Middlewares',
            'Controller/Middleware',
            'Utils/Middlewares',
            'Utils/Middleware',
            'Util/Middlewares',
            'Util/Middleware',
            'Middlewares',
            'Middleware'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'middleware');
    }

    private function createRepository(string $name): void
    {
        $info = $this->getInfo($name, 'Repository', 'RepositoryTemplate');
        $this->smartInfo($info, 'Repositories', 'Repository');
        $tName = strtolower(str_replace('Repository', '', $info['className']));
        if ($tName[-1] == 'y') {
            $tName = substr($tName, 0, -1) . 'ies';
        } else {
            $tName .= 's';
        }
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $code = str_replace("__tableName__", $tName, $code);
        $this->createFile($info['className'], $info['path'], $code, 'repository');
    }

    private function createStore(string $name): void
    {
        $info = $this->getInfo($name, 'Store', 'StoreRedisTemplate');
        $this->smartInfo($info, 'Stores', 'Store');
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'store');
    }

    private function createModel(string $name): void
    {
        $info = $this->getInfo($name, 'Model', 'ModelTemplate');
        $this->smartInfo(
            $info,
            'Entity/Models',
            'Entity/Model',
            'Entities/Models',
            'Entities/Model',
            'Models',
            'Model'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'model');
    }

    private function createDto(string $name): void
    {
        $info = $this->getInfo($name, 'Dto', 'DtoTemplate');
        $this->smartInfo(
            $info,
            'Entity/Dto',
            'Entities/Dto',
            'Dto',
            'Entity',
            'Entities'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'dto');
    }

    private function createRequest(string $name): void
    {
        $info = $this->getInfo($name, 'Request', 'RequestTemplate');
        $this->smartInfo(
            $info,
            'Entity/Requests',
            'Entity/Request',
            'Entities/Requests',
            'Entities/Request',
            'Requests',
            'Request',
            'Entity',
            'Entities'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'request');
    }

    private function createResponse(string $name): void
    {
        $info = $this->getInfo($name, '', 'ResponseTemplate');
        $this->smartInfo(
            $info,
            'Controllers',
            'Controller',
            'Utils/Responses',
            'Utils/Response',
            'Util/Responses',
            'Util/Response',
            'Responses',
            'Response'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'response');
    }

    private function createJob(string $name): void
    {
        $info = $this->getInfo($name, 'Job', 'JobTemplate');
        $this->smartInfo(
            $info,
            'Threads/Jobs',
            'Threads/Job',
            'Thread/Jobs',
            'Thread/Job',
            'Jobs',
            'Job',
            'Threads',
            'Thread'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'job');
    }

    private function createProcess(string $name): void
    {
        $info = $this->getInfo($name, 'Process', 'ProcessTemplate');
        $this->smartInfo(
            $info,
            'Threads/Processes',
            'Threads/Process',
            'Thread/Processes',
            'Thread/Process',
            'Processes',
            'Process',
            'Threads',
            'Thread'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'process');
    }

    private function createCluster(string $name): void
    {
        $info = $this->getInfo($name, 'Cluster', 'ClusterTemplate');
        $this->smartInfo(
            $info,
            'Threads/Clusters',
            'Threads/Cluster',
            'Thread/Clusters',
            'Thread/Cluster',
            'Clusters',
            'Cluster',
            'Threads',
            'Thread'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'cluster');
    }

    private function createWebSocket(string $name): void
    {
        $info = $this->getInfo($name, 'WebSocket', 'WebSocketTemplate');
        $this->smartInfo(
            $info,
            'Threads/WebSockets',
            'Threads/WebSocket',
            'Thread/WebSockets',
            'Thread/WebSocket',
            'WebSockets',
            'WebSocket',
            'Threads',
            'Thread'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'websocket');
    }

    private function createConfig(string $name): void
    {
        $info = $this->getInfo($name, 'DbConfig', 'DbConfigTemplate');
        $this->smartInfo(
            $info,
            'Configs/Databases',
            'Config/Database',
            'Configs',
            'Config'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'db config');
    }

    private function createRedisConfig(string $name): void
    {
        $info = $this->getInfo($name, 'RedisConfig', 'RedisConfigTemplate');
        $this->smartInfo($info, 'Configs/Redis', 'Configs', 'Config');
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'redis config');
    }

    private function createCmd(string $name): void
    {
        $info = $this->getInfo($name, 'Cmd', 'CmdTemplate');
        $this->smartInfo($info, 'Cmd');
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'cmd');
    }

    private function smartInfo(array &$info, string ...$smartPaths): void
    {
        if (!empty($smartPaths) && $info['path'] == '/') {
            foreach ($smartPaths as $smartPath) {
                $smartPath = trim($smartPath, '/');
                if (is_dir($this->createPath . '/' . $smartPath)) {
                    $info['path'] = '/' . $smartPath . '/';
                    $info['namespace'] .= '\\' . str_replace('/', '\\', $smartPath);
                    break;
                }
            }
        }
    }

    private function createFile(string $fName, string $path, string $code = "", ?string $prefix = null): void
    {
        $path = rtrim($this->createPath . $path, '/');
        $prefix = ($prefix) ? " ({$prefix})" : '';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $fileName = "$path/$fName.php";
        if (!file_exists($fileName)) {
            $fp = fopen($fileName, "x");
            fwrite($fp, $code);
            fclose($fp);
            self::printMessage("{$fName} file created successfully.{$prefix} [file://{$fileName}]", 32);
        } else {
            self::printMessage("The {$fName} file already exist.{$prefix}");
        }
    }

    private function getInfo(string $way, string $prefix, string $templateName): array
    {
        $root = ($this->createPath != Kernel::$pathRoot)
            ? ucwords(basename($this->createPath))
            : '';
        $way = ltrim($this->ucWord($way) . $prefix, '/');
        $className = basename($way);
        $way = str_replace($className, '', $way);
        if (isset($this->args['options']['mvc'])) {
            $way = '/' . rtrim($way, '/');
            switch ($prefix) {
                case "Controller":
                    $way = 'Controllers' . $way;
                    break;
                case "Service":
                    $way = 'Services' . $way;
                    break;
                case "Middleware":
                    $way = 'Middlewares' . $way;
                    break;
                case "Store":
                case "Repository":
                    $way = 'Repositories' . $way;
                    break;
                case "Model":
                    $way = 'Models' . $way;
                    break;
                case "Dto":
                    $way = 'Entity' . $way;
                    break;
                case "Request":
                    $way = 'Requests' . $way;
                    break;
                case "Job":
                    $way = 'Jobs' . $way;
                    break;
                case "Cluster":
                case "Process":
                    $way = 'Processes' . $way;
                    break;
                case "WebSocket":
                    $way = 'Sockets' . $way;
                    break;
                case "Cmd":
                    $way = 'Commands' . $way;
                    break;
                default:
                    $way = 'Utils' . $way;
            }
        }

        return [
            'namespace' => str_replace('/', '\\', trim($root . '/' . $way, " \t\n\r\0\x0B/")),
            'className' => $className,
            'path' => '/' . ($this->createPath != Kernel::$pathRoot
                    ? $way : lcfirst($way)
                ),
            'template' => $this->templatePath . '/' . $templateName,
        ];
    }

    private function ucWord(string $str): string
    {
        return implode('/', array_map(fn($word) => ucfirst($word), explode('/', $str)));
    }

    public static function help(): void
    {
        $cl = 34;
        self::printTitle("Make Help", $cl);
        self::printLabel("extra make [args...] -[flags...] --[options...]", $cl);
        self::printMessage("args - names of generated templates", $cl);
        self::printMessage("flags - selection of templates to be created", $cl);

        // flags
        self::printLabel("-[flags...]", $cl);
        self::print("a - Template RestController, prefix RestController", $cl);
        self::print("c - Template Controller, prefix Controller", $cl);
        self::print("", $cl);
        self::print("m - Template ModelBase, prefix Model", $cl);
        self::print("d - Template DtoObject, prefix Dto", $cl);
        self::print("q - Template RequestObject, prefix Request", $cl);
        self::print("e - Template Custom Response", $cl);
        self::print("", $cl);
        self::print("s - Template Service, prefix Service", $cl);
        self::print("l - Template Middleware, prefix Middleware", $cl);
        self::print("r - Template Repository, prefix Repository", $cl);
        self::print("t - Template Store, prefix Store", $cl);
        self::print("", $cl);
        self::print("j - Template Job, prefix Job", $cl);
        self::print("p - Template Process, prefix Process", $cl);
        self::print("u - Template Cluster, prefix Cluster", $cl);
        self::print("w - Template WebSocket, prefix WebSocket", $cl);
        self::print("", $cl);
        self::print("D - Template DbConfig, prefix Config", $cl);
        self::print("R - Template RedisConfig, prefix RedisConfig", $cl);
        self::print("", $cl);
        self::print("n - Template CustomCmd, dont prefix", $cl);
        self::printLabel("-[flags...]", $cl);

        // options
        self::printLabel("-[options...]", $cl);
        self::print("mvc - structure", $cl);
        self::printLabel("-[options...]", $cl);

        self::printTitle("Make Help", $cl);
    }
}
