<?php

declare(strict_types=1);

namespace Flytachi\Winter\Console\Command;

use Composer\Autoload\ClassLoader;
use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Kernel\Kernel;

class Make extends Cmd
{
    public static string $title = "generate framework component templates";
    private string $createPath;
    private string $templatePath;
    private ClassLoader|false $loader = false;

    public function handle(): void
    {
        self::printTitle("Make", 32);
        $this->templatePath = dirname(__DIR__) . '/Template/Make';
        array_shift($this->args['arguments']);

        if (count($this->args['arguments']) == 0) {
            self::printWarning("Enter the names of the generated templates");
            self::printInfo("Example: call make .Example");
            self::printInfo("Help:    call make [--help or -h]");
        } elseif (!count($this->args['flags'])) {
            self::printWarning("Specify template types with flags");
            self::printInfo("Example: call make -asrm .Example");
            self::printInfo("Help:    call make [--help or -h]");
        } else {
            $this->resolution();
        }

        self::printTitle("Make", 32);
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
            if (in_array('a', $this->args['flags'])) {
                $this->createRestController($templateName);
            }
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
            'Entity/Dto',
            'Entities/Dto',
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
        $bestNsPrefix = '';
        $bestDir      = null;
        foreach ($namespaceMap as $nsPrefix => $dirs) {
            $dir = realpath($dirs[0]);
            if (
                $dir
                && str_starts_with($fullClassNs, $nsPrefix)
                && strlen($nsPrefix) > strlen($bestNsPrefix)
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
            if ($dir && str_starts_with($dir, Kernel::$pathRoot)) {
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

        // usage
        self::printLabel("Usage", $cl);
        self::print("call make [args...] -[flags...] --[options...]", $cl);
        self::print("", $cl);
        self::print("args    - one or more template names (dot-notation namespace)", $cl);
        self::print("flags   - which template types to generate", $cl);
        self::print("options - extra generation modifiers", $cl);
        self::printLabel("Usage", $cl);

        // args / namespace resolution
        self::printLabel("Namespace Resolution (PSR-4 aware)", $cl);
        self::print(".Test                   -> auto-detect: first PSR-4 dir under app root", $cl);
        self::print("Test                    -> same as .Test", $cl);
        self::print("api.user.Test           -> relative sub-path: appRoot/Api/User/", $cl);
        self::print("acme.app.api.user.Test  -> full PSR-4 namespace resolved to mapped dir", $cl);
        self::print("", $cl);
        self::print("File path and class namespace are always derived from composer PSR-4 map.", $cl);
        self::printLabel("Namespace Resolution (PSR-4 aware)", $cl);

        // flags — HTTP layer
        self::printLabel("Flags — HTTP", $cl);
        self::print("-a   RestController   (suffix: Controller)  REST API controller", $cl);
        self::print("-c   Controller       (suffix: Controller)  Web/view controller", $cl);
        self::print("-m   Middleware       (suffix: Middleware)  HTTP middleware", $cl);
        self::printLabel("Flags — HTTP", $cl);

        // flags — Data layer
        self::printLabel("Flags — Data", $cl);
        self::print("-e   Entity           (no suffix)           ORM entity / model", $cl);
        self::print("-d   Dto              (suffix: Dto)         Data Transfer Object", $cl);
        self::print("-q   Request          (suffix: Request)     Validated request object", $cl);
        self::print("-p   Response         (no suffix)           Custom HTTP response", $cl);
        self::printLabel("Flags — Data", $cl);

        // flags — Business layer
        self::printLabel("Flags — Business", $cl);
        self::print("-s   Service          (suffix: Service)     Business logic service", $cl);
        self::print("-r   Repository       (suffix: Repository)  Data access repository", $cl);
        self::print("-t   Store            (suffix: Store)       Redis-backed store", $cl);
        self::printLabel("Flags — Business", $cl);

        // flags — Async / Process
        self::printLabel("Flags — Async / Process", $cl);
        self::print("-J   Job              (suffix: Job)         Async queue job", $cl);
        self::print("-P   Process          (suffix: Process)     Long-running process", $cl);
        self::print("-N   Daemon           (suffix: Daemon)      Background daemon", $cl);
        self::print("-W   WebSocket        (suffix: WebSocket)   WebSocket handler", $cl);
        self::printLabel("Flags — Async / Process", $cl);

        // flags — Config / Console
        self::printLabel("Flags — Config / Console", $cl);
        self::print("-D   DbConfig         (suffix: DbConfig)    Database configuration", $cl);
        self::print("-R   RedisConfig      (suffix: RedisConfig) Redis configuration", $cl);
        self::print("-n   Cmd              (no suffix)           Custom console command", $cl);
        self::printLabel("Flags — Config / Console", $cl);

        // options
        self::printLabel("Options", $cl);
        self::print("--mvc   Wrap output path in MVC category folders", $cl);
        self::print("        e.g. Controller → Controllers/, Service → Services/, ...", $cl);
        self::printLabel("Options", $cl);

        // examples
        self::printLabel("Examples", $cl);
        self::print("call make .User -acsr", $cl);
        self::print("call make api.user.Profile -a", $cl);
        self::print("call make .Order -asr --mvc", $cl);
        self::print("call make acme.app.http.User -a", $cl);
        self::printLabel("Examples", $cl);

        // docs
        self::printLabel("Documentation", $cl);
        self::print("https://winterframe.net/#", $cl);
        self::printLabel("Documentation", $cl);

        self::printTitle("Make Help", $cl);
    }
}
