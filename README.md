# Winter Kernel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-kernel.svg)](https://packagist.org/packages/flytachi/winter-kernel)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-kernel.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-kernel)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

📖 **[winterframe.net/docs](https://winterframe.net/docs/introduction)** · [Install](https://winterframe.net/docs/installation) · [Quick start](https://winterframe.net/docs/quickstart) · [Key concepts](https://winterframe.net/docs/concepts)

> Every link here points at `winterframe.net` and is language-neutral — the site serves
> the page in your language, RU or EN. Both are complete.

The kernel of the Winter framework: the package you install, and the only one you install
directly. It turns a directory of classes into a running application — routing, request
binding and validation, responses, dependency injection, managed processes and daemons,
scheduling, localization, diagnostics and the console.

It is a **library, not a skeleton**. There is nothing to scaffold and no directory tree to
create: you add it to a project, write one class saying what the application contains, and
run it.

## What is not in it

The database layer and the Redis client are **separate packages**, installed on demand.
The kernel knows just enough about them to find your classes and hand them over when the
package is there — an application that talks only to an external API should not carry an
ORM, a connection pool and a migration engine it never loads.

| Package | What it adds | Docs |
|---|---|---|
| `flytachi/winter-ppa` | Repositories, entities, query builder, migrations, DB pool | [PPA](https://winterframe.net/docs/ppa) |
| `flytachi/winter-redis` | Pooled Redis: prefixed stores, hashes, lists, streams | [Redis](https://winterframe.net/docs/redis) |

See [Ecosystem](https://winterframe.net/docs/ecosystem) for the full picture, including the
libraries the kernel already brings with it (DI, logger, CDO, thread).

---

## Requirements

| | |
|---|---|
| PHP | **8.4+** |
| Required extensions | `pcntl`, `posix`, `fileinfo` |
| For the HTTP server | `swoole` — coroutines, connection pooling, static files |
| Optional | `pdo` (database) · `bcmath`, `decimal` (exact numbers) · `simplexml` (XML bodies) |

Everything else comes from composer. Details: [Installation](https://winterframe.net/docs/installation).

---

## Install

```bash
composer require flytachi/winter-kernel
```

## Mini start — two files

**`bootstrap.php`** — loads the autoloader and declares what the application contains:

```php
<?php

declare(strict_types=1);

use Flytachi\Winter\Kernel\App\Attribute\EnableWeb;
use Flytachi\Winter\Kernel\WinterApplication;

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

**`call`** — the single entry point for everything, the server included
(`chmod +x call` once):

```php
#!/usr/bin/env php
<?php

chdir(__DIR__);
require './bootstrap.php';

Application::main($argv);
```

`chdir()` is not decoration: it ties `.env`, `storage/` and `resources/` to the project
rather than to wherever the command was typed, which is what makes calls from cron,
systemd and `docker exec` predictable.

That is a working project — no `.env`, no directories. The kernel creates what it needs
when it needs it.

```bash
php call            # the command list
php call run        # bring the application up
php call run dev    # same, restarting when a .php file changes
```

Add a controller anywhere under the project; the scan finds it, no registration:

```php
use Flytachi\Winter\Kernel\Http\Stereotype\Controller;
use Flytachi\Winter\Kernel\Route\Annotation\GetMapping;

final class PingController extends Controller
{
    #[GetMapping('/ping')]
    public function ping(): array
    {
        return ['pong' => true];
    }
}
```

```bash
curl http://localhost:8000/ping     # {"pong":true}
```

Walk-through with a path variable, a query parameter and the JSON it returns:
[Quick start](https://winterframe.net/docs/quickstart).

---

## What the application contains — `#[Enable*]`

The attributes on the application class are the manifest. Each adds a component; declare
none and boot fails rather than starting an application that does nothing.

| Attribute | Effect | Docs |
|---|---|---|
| `#[EnableWeb]` | the Swoole HTTP server | [Routing](https://winterframe.net/docs/routing) |
| `#[EnableScheduler]` | runs `#[Scheduled]` methods on their triggers | [Scheduler](https://winterframe.net/docs/scheduler) |
| `#[EnableProcess(Foo::class)]` | a managed worker beside the server | [Processes](https://winterframe.net/docs/processes) |
| `#[EnableDaemon(Bar::class)]` | a supervised fleet of workers | [Daemons](https://winterframe.net/docs/daemons) |
| `#[EnableAsync]` | proxies `#[Async]` methods so they run off the request | [Async](https://winterframe.net/docs/async) |
| `#[EnableActuator]` | `/actuator` — health, pools, metrics, mappings | [Actuator](https://winterframe.net/docs/actuator) |
| `#[Import('vendor/pkg', '/prefix')]` | mounts a package under a URL prefix | [Packages](https://winterframe.net/docs/packages) |

Everything else is an ordinary class the scan finds — there are no configuration hooks to
override:

```php
#[Configuration] / #[Bean]   // DI factories            → dependency-injection
WebConfigurer                // host, port, CORS, static → web-configuration
LoggingConfigurer            // extra log channels       → logging
```

Full list and the rules: [Components](https://winterframe.net/docs/components).

---

## Documentation map

The whole reference lives at **[winterframe.net](https://winterframe.net/docs/introduction)**.
The tree below mirrors the site's own navigation.

**1. Introduction** — what this is, and whether it fits you

1. [What is Winter](https://winterframe.net/docs/introduction)
2. [Philosophy](https://winterframe.net/docs/philosophy)
3. [Key concepts](https://winterframe.net/docs/concepts)
4. [Ecosystem](https://winterframe.net/docs/ecosystem)

**2. Getting started** — from an empty directory to a served request

1. [Installation](https://winterframe.net/docs/installation)
2. [Project structure](https://winterframe.net/docs/directory-structure)
3. [Configuration](https://winterframe.net/docs/configuration)
4. [Quick start](https://winterframe.net/docs/quickstart)
5. [Dependency injection](https://winterframe.net/docs/dependency-injection)
6. [Basic connections](https://winterframe.net/docs/basic-connections)

**3. Web basics** — everything between the request and the response

1. [Routing](https://winterframe.net/docs/routing)
2. [Web-layer configuration](https://winterframe.net/docs/web-configuration)
3. [Controllers](https://winterframe.net/docs/controllers)
4. [Middleware](https://winterframe.net/docs/middleware)
5. [Requests and parameter binding](https://winterframe.net/docs/requests)
6. [Validation](https://winterframe.net/docs/validation)
7. [Responses](https://winterframe.net/docs/responses)
8. [Cookies](https://winterframe.net/docs/cookies)
9. [Views](https://winterframe.net/docs/views)
10. [Error handling](https://winterframe.net/docs/error-handling)

**4. Background components** — work that outlives a request

1. [Components](https://winterframe.net/docs/components)
2. [Processes](https://winterframe.net/docs/processes)
3. [Daemons](https://winterframe.net/docs/daemons)
4. [Scheduler](https://winterframe.net/docs/scheduler)

**5. Database (PPA)** — needs `flytachi/winter-ppa`

1. [PHP Persistence API](https://winterframe.net/docs/ppa)
2. [Connection](https://winterframe.net/docs/db-configuration)
3. [Entities](https://winterframe.net/docs/entities)
4. [Repositories](https://winterframe.net/docs/repository)
5. [Pagination](https://winterframe.net/docs/pagination)
6. [Migrations](https://winterframe.net/docs/migrations)
7. [Connection pool](https://winterframe.net/docs/ppa-pooling)

**6. Redis** — needs `flytachi/winter-redis`

1. [Redis](https://winterframe.net/docs/redis)
2. [Configuration](https://winterframe.net/docs/redis-configuration)
3. [Stores](https://winterframe.net/docs/redis-stores)
4. [Hashes](https://winterframe.net/docs/redis-hashes)
5. [Lists](https://winterframe.net/docs/redis-lists)
6. [Streams](https://winterframe.net/docs/redis-streams)
7. [Connection pool](https://winterframe.net/docs/redis-pooling)

**7. CLI** — the `call` command and everything under it

1. [Overview](https://winterframe.net/docs/console-overview)
2. [make](https://winterframe.net/docs/cmd-make) — generate a component
3. [cfg](https://winterframe.net/docs/cmd-cfg) — `.env`, keys, Docker, completion
4. [run](https://winterframe.net/docs/cmd-run) — serve the application
5. [db](https://winterframe.net/docs/cmd-db) — ping, migrate, SQL preview, pools
6. [mapping](https://winterframe.net/docs/cmd-mapping) — the route table
7. [storage](https://winterframe.net/docs/cmd-storage) — service directories
8. [script](https://winterframe.net/docs/cmd-script) — your own commands
9. [di](https://winterframe.net/docs/cmd-di) — scanner cache and `#[Async]` proxies
10. [process](https://winterframe.net/docs/cmd-process) — start, stop, status
11. [daemon](https://winterframe.net/docs/cmd-daemon) — fleets of workers
12. [schedule](https://winterframe.net/docs/cmd-schedule) — the scheduler

**8. Advanced** — the parts you reach for later

1. [Logging](https://winterframe.net/docs/logging)
2. [Localization](https://winterframe.net/docs/localization)
3. [Asynchronous calls](https://winterframe.net/docs/async)
4. [Actuator / Health](https://winterframe.net/docs/actuator)
5. [File storage](https://winterframe.net/docs/file-storage)
6. [Packages](https://winterframe.net/docs/packages)
7. [Runtimes (FPM and Swoole)](https://winterframe.net/docs/runtime)

---

## License

MIT — see [LICENSE](LICENSE).
