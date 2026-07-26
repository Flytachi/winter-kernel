# Winter — Quickstart (from zero to running)

This is the shortest honest path from an empty folder to a running Winter
application. You just did:

```bash
mkdir my-app && cd my-app
composer require flytachi/winter-kernel
```

Now you have `vendor/` and nothing else. This page adds the handful of files a
project needs, explains the **one entry point** (`App::run()`), and shows how to
run the app.

If you would rather not type these files by hand, skip to
[Using the starter template](#using-the-starter-template) — `composer
create-project flytachi/winter` writes all of them for you.

---

## The mental model in one picture

You write **one** application class that declares **what your app contains** —
its *components*. A component is any long-lived thing: the web server, a
background `Process`, a supervised `Daemon`, the `Scheduler`. One command brings
them all up in a single process.

```
   App::components() = [ http, process, daemon, scheduler ]
                                   │
                             php call run          ← run the whole app (prod)
                             php call run dev       ← same + MemoryWatcher (dev)
                                   │
                        ONE Swoole process
                 ┌─────────────┬──────────┬───────────┐
              HTTP :8000     Process     Daemon     Scheduler
                            (addProcess, supervised, co-terminating)
```

- The web tier is **just a component** (`Component::http()`), not a hard
  requirement. Declare it and `call run` serves HTTP; omit it and the app runs
  **headless** (background components only).
- **Swoole** hosts everything in one process, like a JVM.
- **FPM** hosts *only the web tier*, one request at a time — because php-fpm is
  not your process. Anything long-lived runs as its own `call` process next to
  it. (See [Deployment shapes](#deployment-shapes).)

You do not choose a "runtime" per app. You list components; the substrate decides
how many share a process.

---

## Step 1 — `composer.json` autoload

`composer require` created a `composer.json`. Add one PSR-4 root so the kernel's
scanner and router can discover your classes:

```json
{
    "type": "project",
    "autoload": {
        "psr-4": { "Main\\": "main/" }
    },
    "require": {
        "php": ">=8.3",
        "flytachi/winter-kernel": "^3.0"
    }
}
```

Then:

```bash
composer dump-autoload
```

Every class under `main/` is now namespace `Main\`. Add more PSR-4 roots as the
project grows — the scanner follows all of them.

---

## Step 2 — `bootstrap.php` (your application class)

This is the heart of the project — the equivalent of a Spring
`@SpringBootApplication`. It configures the kernel **and declares your
components**.

```php
<?php

declare(strict_types=1);

use Flytachi\Winter\K2\Application;
use Flytachi\Winter\K2\App\Component;
use Flytachi\Winter\K2\Kernel;

require __DIR__ . '/vendor/autoload.php';

final class App extends Application
{
    protected static function configure(): void
    {
        Kernel::init(pathRoot: __DIR__);
    }

    /**
     * What this application is made of.
     * Add or remove a line — the app gains or loses that capability.
     */
    protected static function components(): array
    {
        return [
            Component::http(port: 8000),   // web server (optional)
            // Component::process(\Main\KernelSys::class),
            // Component::daemon(\Main\Emails::class),
            // Component::scheduler(),
        ];
    }
}
```

`App extends Application` (not `BaseBoot`) — `Application` adds `components()` and
the `run()` / `serve()` entry on top of every `BaseBoot` hook you already know
(`providers()`, `channels()`, `httpCors()`, `health()`, `plugins()`). See
[`../configuration/01-kernel.md`](../configuration/01-kernel.md) for those.

---

## Step 3 — the entry files

### `call` — the one launcher

This is your `java -jar`. Every runtime flows through it.

```php
#!/usr/bin/env php
<?php

if (PHP_VERSION_ID >= 80300) {
    chdir(__DIR__);
    require './bootstrap.php';
    App::run($argv);            // ← the single entry point (the app's main())
} else {
    echo "Please use PHP 8.3 or higher.\n";
}
```

`App::run($argv)` boots once and dispatches the console command — `run`,
`run dev`, `make`, `daemon`, `schedule`, or your own. Make it executable:

```bash
chmod +x call
```

### `public/index.php` — the FPM web adapter

FPM is not a persistent process, so it cannot go through `call run`. It gets its
own two-line front controller that runs the web tier per request:

```php
<?php
require '../bootstrap.php';
App::web();
```

You only need this file if you deploy under PHP-FPM. Under Swoole it is unused —
`Component::http()` is the server.

---

## Step 4 — `.env` and `storage/`

```bash
mkdir -p storage/{cache,logs}
chmod -R 777 storage
```

Minimal `.env`:

```dotenv
WINTER_KEY=change-me
TIME_ZONE=UTC
DEBUG=true

LOG_LEVEL=info
LOG_FORMAT=line
LOG_OUTPUT=auto
```

Generate a real project key:

```bash
php call cfg key -g
```

Full `LOG_*` reference: [`../configuration/02-logging.md`](../configuration/02-logging.md).

---

## Step 5 — your first controller

```php
<?php

declare(strict_types=1);

namespace Main;

use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

class MainController extends Controller
{
    #[RequestMapping]                       // no path → GET /
    public function hello(): ResponseEntity
    {
        return ResponseEntity::ok('Hello from Winter');
    }
}
```

No registration needed — it is discovered on scan. Confirm with:

```bash
php call mapping show
```

---

## Step 6 — run it

Your folder now looks like this:

```
my-app/
├── bootstrap.php        App extends Application (config + components)
├── call                 App::run($argv)   ← the one entry
├── composer.json        Main\ → main/
├── public/index.php     App::web()        ← FPM adapter only
├── main/
│   └── MainController.php
├── storage/{cache,logs}/
├── .env
└── vendor/
```

Run it:

| Command | What runs |
|---|---|
| `php call run` | **Production** — every component in one Swoole process, MemoryWatcher off |
| `php call run dev` | **Development** — same, MemoryWatcher on |
| `php call <command>` | Console — `make`, `mapping`, `di`, your commands |
| nginx → `public/index.php` | Web only, under PHP-FPM |

With only `Component::http()` declared, `php call run` is a web server. Open
`http://0.0.0.0:8000` → `Hello from Winter`. On the console you will see:

```
Application up: http://0.0.0.0:8000
```

> `call run` with a web tier needs ext-swoole (`pecl install swoole`). Without a
> web tier the app runs headless and works without swoole (each component picks
> its own engine).

---

## Step 7 — add a background component

Say you want a worker that runs forever alongside the web server. Write it as a
`Process`:

```php
<?php

declare(strict_types=1);

namespace Main;

use Flytachi\Winter\K2\Process\Process;

final class KernelSys extends Process
{
    public function run(): void
    {
        while ($this->isRunning()) {
            $this->sleep(3);
            // ... periodic work ...
        }
    }
}
```

Add one line to `components()`:

```php
protected static function components(): array
{
    return [
        Component::http(port: 8000),
        Component::process(\Main\KernelSys::class),   // ← new
    ];
}
```

Now `php call run` runs **both** in one Swoole process:

```
Application up: http://0.0.0.0:8000 + [KernelSys]
```

`Ctrl-C` (SIGTERM) stops the server *and* `KernelSys` together — the Swoole
master supervises the companion and terminates it with the server.

The same works for a `Daemon` (`Component::daemon(...)`) and the scheduler
(`Component::scheduler()`). Each companion behaves exactly as if you had launched
it standalone (`call daemon|process|schedule`).

### Headless (no web)

Drop `Component::http()` and `call run` runs only the background components — one
in the foreground, several under a small supervisor. Useful for a worker-only or
scheduler-only deployment:

```php
protected static function components(): array
{
    return [
        Component::daemon(\Main\Emails::class),
        Component::scheduler(),
    ];
}
```

```
Application up (headless): [Emails, Scheduler]
```

---

## Deployment shapes

The same code runs two ways; only the process layout differs.

### Swoole — all in one (the JVM shape)

```
php call run ── ONE process
   ├─ HTTP :8000
   ├─ KernelSys       (addProcess, supervised)
   ├─ Emails daemon   (addProcess, supervised)
   └─ Scheduler       (addProcess, supervised)
```

One command, one process, co-terminating. This is the recommended shape when the
app has any long-lived component.

### FPM — web on fpm, everything else standalone

FPM only serves the web tier. Long-lived components run as their own processes
(systemd units, separate containers, `-d` detached):

```
nginx → php-fpm → public/index.php → App::web()      # HTTP, per request
+ php call process  main.KernelSys  start -d          # separate process
+ php call daemon   main.Emails     start -d          # separate process
+ php call schedule start -d                           # separate process
```

`components()` does not change — under FPM the non-web entries are simply not
hosted by the request process; you start them yourself. One Docker image, and the
container's `command:` picks the role:

```yaml
web:        command: php call run          # or php-fpm for the FPM shape
worker:     command: php call daemon main.Emails start
scheduler:  command: php call schedule start
```

> **Why FPM is the odd one out:** php-fpm master is not your process — it invokes
> your code per request and recycles the worker. There is no persistent loop for a
> daemon or scheduler to live in, so those always need their own process. If your
> app has a daemon or scheduler, you already need a persistent process — at that
> point Swoole (`call run`) is usually the simpler choice.

---

## Cheat sheet

```bash
# scaffold checklist (fresh clone)
composer install
mkdir -p storage/{cache,logs} && chmod -R 777 storage
chmod +x call
php call cfg key -g

# run
php call run              # production: all components, one process
php call run dev          # development: + MemoryWatcher
php call mapping show     # list routes

# run a single component standalone (split / FPM deploy)
php call process  main.KernelSys start [-d]
php call daemon   main.Emails    start [-d]
php call schedule start [-d]

# production caches
php call mapping build
php call di build
```

---

## Using the starter template

Everything above is generated for you by the starter repository:

```bash
composer create-project flytachi/winter my-app
cd my-app
php call run
```

See [`../starter.md`](../starter.md) for the file-by-file breakdown of the
generated project.

---

## See also

- [`../configuration/01-kernel.md`](../configuration/01-kernel.md) — every `configure()` / hook option
- [`../process/00-overview.md`](../process/00-overview.md) — writing a `Process`
- [`../process/daemon/00-overview.md`](../process/daemon/00-overview.md) — writing a `Daemon`
- [`../schedule/00-overview.md`](../schedule/00-overview.md) — `#[Scheduled]` tasks
- [`../console/00-overview.md`](../console/00-overview.md) — the `call` CLI
