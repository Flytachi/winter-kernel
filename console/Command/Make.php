<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\K2\Kernel;

class Make extends Cmd
{
    public static string $title = "generate framework component templates";
    private string $createPath;
    private string $templatePath;
    private ClassLoader|false $loader = false;

    public function handle(): void
    {
        self::printTitle("Make", 34);
        $this->templatePath = dirname(__DIR__) . '/Template/Make';
        array_shift($this->args['arguments']);

        if (count($this->args['arguments']) == 0) {
            self::printWarning("Enter the names of the generated templates");
            self::printInfo("Example: call make .Example");
            self::printInfo("Help:    call make [--help or -h]");
        } elseif (!count($this->args['flags'])) {
            self::printWarning("Specify template types with flags");
            self::printInfo("Example: call make -csrm .Example");
            self::printInfo("Help:    call make [--help or -h]");
        } else {
            $this->resolution();
        }

        self::printTitle("Make", 34);
    }

    private function resolution(): void
    {
        $total = count($this->args['arguments']);
        $step  = 0;
        foreach ($this->args['arguments'] as $templateName) {
            ++$step;
            if ($total > 1) {
                self::printDivider();
                self::printStep($step, $total, $templateName);
            }
            $templateName = str_replace('.', '/', $templateName);
            $this->createPath = Kernel::$pathRoot;
            // ---
            if (in_array('c', $this->args['flags'])) {
                $this->createController($templateName);
            }
            if (in_array('s', $this->args['flags'])) {
                $this->createService($templateName);
            }
            if (in_array('m', $this->args['flags'])) {
                $this->createMiddleware($templateName);
            }
            if (in_array('r', $this->args['flags'])) {
                $this->createRepository($templateName);
            }
            if (in_array('t', $this->args['flags'])) {
                $this->createStore($templateName);
            }
            if (in_array('e', $this->args['flags'])) {
                $this->createEntity($templateName);
            }
            if (in_array('d', $this->args['flags'])) {
                $this->createDto($templateName);
            }
            if (in_array('q', $this->args['flags'])) {
                $this->createRequest($templateName);
            }
            if (in_array('p', $this->args['flags'])) {
                $this->createResponse($templateName);
            }
            if (in_array('J', $this->args['flags'])) {
                $this->createJob($templateName);
            }
            if (in_array('P', $this->args['flags'])) {
                $this->createProcess($templateName);
            }
            if (in_array('N', $this->args['flags'])) {
                $this->createDaemon($templateName);
            }
            if (in_array('W', $this->args['flags'])) {
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

    private function createController(string $name): void
    {
        $info = $this->getInfo($name, 'Controller', 'ControllerTemplate');
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
            'Middlewares',
            'Middleware',
            'Controllers/Middlewares',
            'Controllers/Middleware',
            'Controller/Middlewares',
            'Controller/Middleware',
            'Utils/Middlewares',
            'Utils/Middleware',
            'Util/Middlewares',
            'Util/Middleware'
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

    private function createEntity(string $name): void
    {
        $info = $this->getInfo($name, '', 'EntityTemplate');
        $this->smartInfo(
            $info,
            'Entity',
            'Entities',
            'Entity/Models',
            'Entity/Model',
            'Entities/Models',
            'Entities/Model',
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
            'Dto',
            'DTOs',
            'Entity/Dto',
            'Entity/DTOs',
            'Entities/DTOs',
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
            'Requests',
            'Request',
            'Entity/Requests',
            'Entity/Request',
            'Entities/Requests',
            'Entities/Request',
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

    private function createDaemon(string $name): void
    {
        $info = $this->getInfo($name, 'Daemon', 'DaemonTemplate');
        $this->smartInfo(
            $info,
            'Threads/Daemons',
            'Threads/Daemon',
            'Thread/Daemons',
            'Thread/Daemon',
            'Daemons',
            'Daemon',
            'Threads',
            'Thread'
        );
        $code = file_get_contents($info['template']);
        $code = str_replace("__namespace__", $info['namespace'], $code);
        $code = str_replace("__className__", $info['className'], $code);
        $this->createFile($info['className'], $info['path'], $code, 'daemon');
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
        $path   = rtrim($this->createPath . $path, '/');
        $label  = $prefix ? "$fName ($prefix)" : $fName;
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $fileName = "$path/$fName.php";
        if (!file_exists($fileName)) {
            $fp = fopen($fileName, "x");
            fwrite($fp, $code);
            fclose($fp);
            self::printBadge($label, 'CREATED', 33, 32);
            self::printInfo("file://$fileName");
        } else {
            self::printBadge($label, 'EXISTS', 33, 33);
        }
    }

    private function getInfo(string $way, string $prefix, string $templateName): array
    {
        if (!$this->loader) {
            $loaders = ClassLoader::getRegisteredLoaders();
            $this->loader = reset($loaders);
        }
        $namespaceMap = $this->loader->getPrefixesPsr4();

        $way = ltrim($this->ucWord($way) . $prefix, '/');
        $className = basename($way);
        $inputPath = trim(str_replace($className, '', $way), '/');
        // Examples:
        //   ".Test"                             → inputPath=""
        //   "main.zer.Test"                     → inputPath="Main/Zer"
        //   "flytachi.winter.kernel.tests.Test" → inputPath="Flytachi/Winter/Kernel/Tests"

        // Step 2: apply --mvc option (prepend category folder to inputPath)
        if (isset($this->args['options']['mvc'])) {
            $mvcFolder = match ($prefix) {
                'Controller'          => 'Controllers',
                'Service'             => 'Services',
                'Middleware'          => 'Middlewares',
                'Store', 'Repository' => 'Repositories',
                'Entity'              => 'Entities',
                'Dto'                 => 'Dto',
                'Request'             => 'Requests',
                'Job'                 => 'Jobs',
                'Daemon', 'Process'   => 'Processes',
                'WebSocket'           => 'Sockets',
                'Cmd'                 => 'Commands',
                default               => 'Utils',
            };
            $inputPath = $inputPath !== '' ? $mvcFolder . '/' . $inputPath : $mvcFolder;
        }

        // Step 3: build full class namespace string for PSR-4 lookup
        $inputNs     = str_replace('/', '\\', $inputPath);
        $fullClassNs = ($inputNs !== '' ? $inputNs . '\\' : '') . $className;

        // Step 4: find longest PSR-4 prefix that matches the full class namespace
        $vendorPath   = realpath(Kernel::$pathRoot . '/vendor');
        $bestNsPrefix = '';
        $bestDir      = null;
        foreach ($namespaceMap as $nsPrefix => $dirs) {
            $dir = realpath($dirs[0]);
            if (
                $dir
                && str_starts_with($fullClassNs, $nsPrefix)
                && strlen($nsPrefix) > strlen($bestNsPrefix)
                && !($vendorPath && str_starts_with($dir, $vendorPath))
            ) {
                $bestNsPrefix = $nsPrefix;
                $bestDir      = $dir;
            }
        }

        if ($bestDir !== null) {
            // PSR-4 match: strip prefix, compute sub-directory and namespace
            $parts = explode('\\', trim(substr($fullClassNs, strlen($bestNsPrefix)), '\\'));
            array_pop($parts); // remove className, keep sub-dirs
            $subDir = implode('/', array_filter($parts));

            $fileDir = $subDir !== '' ? $bestDir . '/' . $subDir : $bestDir;
            $fileNs  = rtrim($bestNsPrefix, '\\')
                . ($subDir !== '' ? '\\' . str_replace('/', '\\', $subDir) : '');

            $this->createPath = $fileDir;
            return [
                'namespace' => $fileNs,
                'className' => $className,
                'path'      => '/',
                'template'  => $this->templatePath . '/' . $templateName,
            ];
        }

        // Step 5: no PSR-4 match — find first PSR-4 dir that lives under Kernel::$pathRoot
        // This handles ".Test" / "Test" / "main.zer.Test" (relative paths without a known ns root)
        $appDir       = null;
        $appNsPrefix  = '';
        foreach ($namespaceMap as $nsPrefix => $dirs) {
            $dir = realpath($dirs[0]);
            if (
                $dir
                && str_starts_with($dir, Kernel::$pathRoot)
                && !($vendorPath && str_starts_with($dir, $vendorPath))
            ) {
                $appDir      = $dir;
                $appNsPrefix = rtrim($nsPrefix, '\\');
                break;
            }
        }

        if ($appDir !== null) {
            $fileDir = $inputPath !== '' ? $appDir . '/' . $inputPath : $appDir;
            $fileNs  = $inputNs !== '' ? $appNsPrefix . '\\' . $inputNs : $appNsPrefix;

            $this->createPath = $fileDir;
            return [
                'namespace' => $fileNs,
                'className' => $className,
                'path'      => '/',
                'template'  => $this->templatePath . '/' . $templateName,
            ];
        }

        // Step 6: absolute fallback — no PSR-4 found under $pathRoot at all
        $root       = ($this->createPath !== Kernel::$pathRoot)
            ? ucwords(basename($this->createPath))
            : '';
        $subPathStr = $inputPath !== '' ? $inputPath . '/' : '';

        return [
            'namespace' => str_replace('/', '\\', trim($root . '/' . $subPathStr, " \t\n\r\0\x0B/")),
            'className' => $className,
            'path'      => '/' . ($this->createPath !== Kernel::$pathRoot ? $subPathStr : lcfirst($subPathStr)),
            'template'  => $this->templatePath . '/' . $templateName,
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

        self::printLabel("Usage", $cl);
        self::print("call make <dot.notation.Name> -[flags] --[options]", $cl);
        self::printLabel("Usage", $cl);

        self::printLabel("Namespace Resolution", $cl);
        self::printKeyValue(".Name", "auto-detect first PSR-4 dir under app root", 26, 36, 37);
        self::printKeyValue("Name", "same as .Name", 26, 36, 37);
        self::printKeyValue("api.user.Name", "relative sub-path: appRoot/Api/User/", 26, 36, 37);
        self::printKeyValue("acme.app.api.user.Name", "full PSR-4 namespace → mapped dir", 26, 36, 37);
        self::printLabel("Namespace Resolution", $cl);

        self::printLabel("Flags — HTTP", $cl);
        self::printBadge('-c', 'Controller      (suffix: Controller)', $cl, 36);
        self::printBadge('-m', 'Middleware       (suffix: Middleware)', $cl, 36);
        self::printLabel("Flags — HTTP", $cl);

        self::printLabel("Flags — Data", $cl);
        self::printBadge('-e', 'Entity           (no suffix)', $cl, 36);
        self::printBadge('-d', 'Dto              (suffix: Dto)', $cl, 36);
        self::printBadge('-q', 'Request          (suffix: Request)', $cl, 36);
        self::printBadge('-p', 'Response         (no suffix)', $cl, 36);
        self::printLabel("Flags — Data", $cl);

        self::printLabel("Flags — Business", $cl);
        self::printBadge('-s', 'Service          (suffix: Service)', $cl, 36);
        self::printBadge('-r', 'Repository       (suffix: Repository)', $cl, 36);
        self::printBadge('-t', 'Store            (suffix: Store)', $cl, 36);
        self::printLabel("Flags — Business", $cl);

        self::printLabel("Flags — Async / Process", $cl);
        self::printBadge('-J', 'Job              (suffix: Job)', $cl, 36);
        self::printBadge('-P', 'Process          (suffix: Process)', $cl, 36);
        self::printBadge('-N', 'Daemon           (suffix: Daemon)', $cl, 36);
        self::printBadge('-W', 'WebSocket        (suffix: WebSocket)', $cl, 36);
        self::printLabel("Flags — Async / Process", $cl);

        self::printLabel("Flags — Config / Console", $cl);
        self::printBadge('-D', 'DbConfig         (suffix: DbConfig)', $cl, 36);
        self::printBadge('-R', 'RedisConfig      (suffix: RedisConfig)', $cl, 36);
        self::printBadge('-n', 'Cmd              (no suffix)', $cl, 36);
        self::printLabel("Flags — Config / Console", $cl);

        self::printLabel("Options", $cl);
        self::printBadge('--mvc', 'wrap path in MVC folders (Controllers/, Services/, ...)', $cl, 36);
        self::printLabel("Options", $cl);

        self::printDivider($cl);

        self::printLabel("Examples", $cl);
        self::printInfo("call make .User -csre");
        self::printInfo("call make api.user.Profile -c");
        self::printInfo("call make .Order -csre --mvc");
        self::printInfo("call make acme.app.http.User -c");
        self::printLabel("Examples", $cl);

        self::printDivider($cl);
        self::printInfo("Docs: https://winterframe.net/docs/3.0.0/cmd-make");

        self::printTitle("Make Help", $cl);
    }
}
