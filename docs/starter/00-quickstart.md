# Winter — Quickstart (from zero to running)

A Winter project is not scaffolded. There is no skeleton to clone and no directory tree
to create: you require the kernel, write one class that says what the application
contains, and run it. Everything else — the DI graph, the route table, the storage
directories — is discovered or created on demand.

This page walks that from an empty directory to a served request, then to a background
worker. For the shortest possible path, the [README](../../README.md) has it in two files.

---

## The mental model

```
call  ──►  bootstrap.php  ──►  Application::main($argv)
                                     │
                                     ├─ boot: Kernel::init → scan → DI, configurers, plugins
                                     │
                                     ├─ `run`  → serve the components in the manifest
                                     └─ else   → hand the verb to the console
```

Two ideas carry the design:

- **The manifest is declarative.** `#[Enable*]` attributes on the application class say
  *what the application is made of*. Nothing is registered by hand.
- **Configuration is discovered, not injected.** There are no hooks to override on the
  application class. A `WebConfigurer`, a `#[Configuration]` class, a `LoggingConfigurer`
  — the scan finds them wherever they live.

---

## Step 1 — composer

```bash
composer require flytachi/winter-kernel
```

Give your own code a namespace in `composer.json`:

```json
{
    "autoload": {
        "psr-4": { "Main\\": "main/" }
    }
}
```

```bash
composer dump-autoload
```

Nothing forces the name `Main\` or the directory `main/` — the scan walks the project
root, so any autoloadable layout works.

## Step 2 — the application class

`bootstrap.php` is the only file the entry points share. It loads the autoloader and
declares the application:

```php
<?php
declare(strict_types=1);

use Flytachi\Winter\K2\App\Attribute\EnableActuator;
use Flytachi\Winter\K2\App\Attribute\EnableWeb;
use Flytachi\Winter\K2\WinterApplication;

require __DIR__ . '/vendor/autoload.php';

#[EnableWeb]
#[EnableActuator]
final class Application extends WinterApplication
{
    public static function main(array $argv): never
    {
        parent::run($argv);
    }
}
```

Declare no component at all and the boot refuses to start rather than running an
application that does nothing.

| Attribute | Adds |
|---|---|
| `#[EnableWeb]` | the Swoole HTTP server |
| `#[EnableActuator]` | `/actuator` diagnostics |
| `#[EnableScheduler]` | runs `#[Scheduled]` methods on their triggers |
| `#[EnableProcess(Foo::class)]` | a managed worker beside the server |
| `#[EnableDaemon(Bar::class)]` | a supervised fleet beside the server |
| `#[EnableAsync]` | proxies `#[Async]` methods so they run off the request |
| `#[Import('vendor/pkg', '/prefix')]` | mounts a plugin package under a URL prefix |

## Step 3 — the entry point

`call` — one launcher for everything (`chmod +x call`):

```php
#!/usr/bin/env php
<?php
chdir(__DIR__);
require './bootstrap.php';
Application::main($argv);
```

There is no `public/index.php`. That file belongs to the FPM document-root model, where
nginx needs a directory to aim at; the Swoole server decides for itself what it serves.

The project is now three files, and it runs:

```bash
php call            # the command list
php call run        # serve
php call run dev    # serve, restarting on file changes
```

## Step 4 — your first controller

Anywhere under the project root:

```php
<?php
declare(strict_types=1);

namespace Main;

use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

#[RequestMapping('/users')]
final class UserController extends Controller
{
    #[GetMapping('/{id:\d+}')]
    public function show(#[PathVariable] int $id): array
    {
        return ['id' => $id];
    }
}
```

Two requirements, both easy to miss:

- the class **extends `Controller`** — the scan collects controllers by that, not by an
  attribute, so a class carrying only mapping attributes contributes no routes;
- the class-level `#[RequestMapping]` prefix combines with each method path, making the
  route above `/users/{id}`.

```bash
php call run
curl localhost:8000/users/42        # {"id":42}
```

Method arguments are filled by annotation — `#[PathVariable]`, `#[RequestParam]`,
`#[RequestBody]`, `#[RequestHeader]` — or by type, where an `HttpRequest` or
`HttpResponse` parameter receives the raw object. See
[`../architecture/04-request/00-overview.md`](../architecture/04-request/00-overview.md).

## Step 5 — configuration, when you need it

`.env` is optional and every variable has a working default. Development usually wants:

```dotenv
DEBUG=true
LOG_LEVEL=debug
```

Web settings live in a class the scan finds — not in the application class:

```php
<?php
declare(strict_types=1);

namespace Main;

use Flytachi\Winter\K2\App\ApplicationArguments;
use Flytachi\Winter\K2\App\Config\ServerSettings;
use Flytachi\Winter\K2\App\Config\WebConfigurerAdapter;

final class WebConfig extends WebConfigurerAdapter
{
    public function configureServer(ServerSettings $server, ApplicationArguments $args): void
    {
        $server->port(8000)
               ->workers(swoole_cpu_num() * 2)
               ->staticPath('resources/static');   // resources/static/app.css → /app.css
    }
}
```

Static serving is opt-in: omit `staticPath()` and no file is ever served, which is what
an API-only service wants. CORS is configured in the same class — see
[`../configuration/03-cors.md`](../configuration/03-cors.md), and
[`../configuration/02-logging.md`](../configuration/02-logging.md) for log channels.

## Step 6 — a background component

Long-lived work is a **Process** (one worker) or a **Daemon** (a supervised fleet):

```php
<?php
declare(strict_types=1);

namespace Main\Process;

use Flytachi\Winter\K2\Process\Process;

final class EmailWorker extends Process
{
    public function run(): void
    {
        while ($this->isRunning()) {
            $this->sleep(1.0);
            // ... work ...
        }
    }
}
```

Run it on its own:

```bash
php call process main.process.EmailWorker start      # foreground
php call process main.process.EmailWorker start -d   # detached
php call process main.process.EmailWorker status
php call process main.process.EmailWorker stop
```

…or beside the server, by adding it to the manifest:

```php
#[EnableWeb]
#[EnableProcess(\Main\Process\EmailWorker::class)]
final class Application extends WinterApplication { /* ... */ }
```

Scheduled methods work the same way — annotate with `#[Scheduled]`, add
`#[EnableScheduler]`. See [`../process/00-overview.md`](../process/00-overview.md) and
[`../schedule/00-overview.md`](../schedule/00-overview.md).

---

## Project layout

Only two directories are conventional, and both appear on demand:

```
composer.json
bootstrap.php          the application class
call                   the entry point
main/                  your code (any autoloadable namespace)
resources/
  static/              web assets — served only when staticPath() says so
  views/               ResponseView's default root
storage/
  logs/ cache/ runnable/     created when first used, never committed
```

`storage/` is deliberately separate from `resources/`: one is written by the runtime,
the other is read by it. Views are `include`d PHP, so the directory the framework
executes from is never the directory it writes to.

## Deployment shapes

| Shape | Command | Notes |
|---|---|---|
| Server | `php call run` | one long-lived Swoole process; log to stdout and let the orchestrator collect |
| Server + workers | `php call run` with `#[EnableProcess]` / `#[EnableDaemon]` | workers supervised beside the server |
| Headless | `php call run` with no `#[EnableWeb]` | workers and scheduler only, no HTTP |
| One-off | `php call <verb>` | console commands, migrations, diagnostics |

In containers keep `LOG_OUTPUT` at its default (stdout) and let the orchestrator collect
it; give `storage/` a volume only if the runtime records must survive a restart.

## Where to go next

- [`../architecture/01-routing.md`](../architecture/01-routing.md) — routing and the request pipeline
- [`../configuration/07-di.md`](../configuration/07-di.md) — dependency injection
- [`../ppa/00-overview.md`](../ppa/00-overview.md) — the database layer
- [`../process/00-overview.md`](../process/00-overview.md) — processes and daemons
- [`../console/00-overview.md`](../console/00-overview.md) — the console
