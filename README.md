# Winter Kernel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-kernel.svg)](https://packagist.org/packages/flytachi/winter-kernel)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-kernel.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-kernel)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

The kernel of the Winter framework — a PHP 8.4 library that turns a directory of
classes into a running application. It carries the HTTP layer, dependency injection,
the database layer (PPA), managed processes and daemons, scheduling, and the console.

It is a **library, not a skeleton**: there is nothing to scaffold and no directory tree
to create. You add it to a project, write one class that says what the application
contains, and run it.

---

## Requirements

| | |
|---|---|
| PHP | **8.4+** |
| Extensions | `pcntl`, `posix`, `fileinfo` (required) · `swoole` (for the HTTP server, coroutines and connection pooling) · `pdo` for a database |

Everything else is pulled by composer.

---

## Install

```bash
composer require flytachi/winter-kernel
```

## The whole application: two files

**`bootstrap.php`** — loads the autoloader and declares what the application contains:

```php
<?php
declare(strict_types=1);

use Flytachi\Winter\K2\App\Attribute\EnableWeb;
use Flytachi\Winter\K2\WinterApplication;

require __DIR__ . '/vendor/autoload.php';

#[EnableWeb]
final class Application extends WinterApplication
{
    public static function main(array $argv): never
    {
        parent::run($argv);
    }
}
```

**`call`** — the single entry point (make it executable with `chmod +x call`):

```php
#!/usr/bin/env php
<?php
chdir(__DIR__);
require './bootstrap.php';
Application::main($argv);
```

That is a working project. No `.env`, no directories — the kernel creates what it needs,
when it needs it.

```bash
php call            # console: the command list
php call run        # bring the application up
php call run dev    # same, restarting on file changes
```

Add a controller anywhere under the project and it is found by the scan:

```php
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

final class PingController extends Controller
{
    #[GetMapping('/ping')]
    public function ping(): array
    {
        return ['pong' => true];
    }
}
```

---

## What the application contains — `#[Enable*]`

The attributes on the application class are the manifest. Each one adds a component;
declare none and the boot fails rather than starting an application that does nothing.

| Attribute | Effect |
|---|---|
| `#[EnableWeb]` | the Swoole HTTP server |
| `#[EnableActuator]` | `/actuator` diagnostics (health, info, metrics, mappings) |
| `#[EnableScheduler]` | runs `#[Scheduled]` methods on their triggers |
| `#[EnableProcess(Foo::class)]` | a managed worker beside the server |
| `#[EnableDaemon(Bar::class)]` | a supervised fleet of workers beside the server |
| `#[EnableAsync]` | proxies `#[Async]` methods so they run off the request |
| `#[Import('vendor/pkg', '/prefix')]` | mounts a plugin package under a URL prefix |

Everything else is configured by ordinary classes the scan finds — there are no
configuration hooks to override:

```php
#[Configuration] / #[Bean]   // DI factories
WebConfigurer                // host, port, Swoole tuning, CORS, static files
LoggingConfigurer            // extra log channels
```

---

## Project layout

Only two directories are conventional, and both are optional:

```
resources/
  static/    web assets — served by Swoole when enabled (see WebConfigurer)
  views/     view files — ResponseView's default root
storage/
  logs/ cache/ runnable/    created on demand, never committed
```

There is no `public/`: that belongs to the FPM document-root model, and the server here
decides for itself what it serves. Static files are opt-in:

```php
final class WebConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->port(8000)
               ->staticPath('resources/static');   // resources/static/app.css → /app.css
    }
}
```

## Configuration

`.env` is optional; every variable has a working default.

| Variable | Default | Meaning |
|---|---|---|
| `DEBUG` | `false` | verbose errors and a full rescan on every boot |
| `LOG_LEVEL` | *(empty — logging off)* | `debug`…`emergency` |
| `LOG_OUTPUT` | `auto` → stdout | `stdout`, `stderr`, `file`, `syslog`, `null` |
| `LOG_FILE` | `storage/logs/<channel>.log` | absolute path when `LOG_OUTPUT=file` |
| `SERVER_WORKERS` | Swoole default | worker count |
| `WINTER_KEY` | *(none)* | signs payloads handed to background processes |
| `PPA_POOL_TELEMETRY` | `5` | seconds between pool-stat publishes; `0` disables |

---

## Console

```bash
php call                       # command list
php call run [dev]             # serve
php call make -c UserController        # scaffold a class
php call daemon  <dot.Class> start [-d] | stop | status
php call process <dot.Class> start [-d] | stop | status
php call db      ping | migrate | sql | pool
php call schedule start [-d] | stop | status
php call cfg     completion -i         # shell completion
```

Class names use dot notation: `main.process.Emails` → `Main\Process\Emails`.

---

## Runtimes

The kernel runs the same application two ways:

- **Swoole** — one long-lived server process; coroutines, connection pooling and static
  files handled in C. This is the primary target.
- **CLI / plain processes** — the console, and processes or daemons started on their own.

FPM is not served by the kernel itself; it is moving to a separate `winter-fpm` project.

---

## Documentation

- [`docs/starter/00-quickstart.md`](docs/starter/00-quickstart.md) — from an empty
  directory to a served request, then to a background worker.
- [`docs/`](docs) — the reference for each subsystem (routing, request binding,
  responses, PPA, processes and daemons, scheduling, console, configuration).

## Development

```bash
vendor/bin/phpunit                       # the default suite
vendor/bin/phpunit --group integration   # real forks, signals and servers
vendor/bin/phpcs                         # PSR-12
```

## License

MIT — see [LICENSE](LICENSE).
