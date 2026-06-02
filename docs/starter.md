# Winter — Project Starter

The recommended way to start a Winter project is the
[`flytachi/winter`](https://github.com/flytachi/winter) starter
repository. It is a minimal Composer **project** package that pulls in
`flytachi/winter-kernel`, ships a single `Boot` class with every hook
documented in-place, and is ready to run via FPM or CLI after one
command.

This page walks the starter end-to-end so you can rebuild it by hand
in an existing repo, or simply understand what each file does.

---

## TL;DR — create a project

```bash
composer create-project flytachi/winter my-app
cd my-app
php call run dev                 # http://0.0.0.0:8000
```

`composer create-project` runs `post-create-project-cmd`, which does:

1. `chmod -R 777 storage`
2. `php call cfg init` — patches `composer.json` (rewrites `name` to
   `project/<dir>`, blanks `authors`, removes keywords), copies `.env`
   from template, generates a fresh 64-char `WINTER_KEY`, and drops the
   PhpStorm meta stub.
3. Prints a tip about installing shell completion.

After that you have a working project — open `main/MainController.php`
and start writing.

---

## Directory layout

```
my-app/
├── bootstrap.php       — defines Boot extends BaseBoot (all hooks documented)
├── call                — CLI entry; runs Boot::cli($argv)
├── composer.json       — project package; depends on flytachi/winter-kernel
├── public/
│   ├── index.php       — FPM / Swoole front controller; runs Boot::web()
│   └── static/         — web-served assets
├── main/               — PSR-4 root (namespace Main\)
│   └── MainController.php
├── storage/
│   ├── cache/          — kernel + app caches (mapping.php, di.php, …)
│   └── logs/           — log files when LOG_OUTPUT=file
├── .env                — environment variables (WINTER_KEY, LOG_*, etc.)
└── vendor/
```

Three things you'd add as the project grows:

| Add when…                                 | Path                |
|-------------------------------------------|---------------------|
| You need view templates                   | `resources/`        |
| You add a Swoole runtime                  | `server.php`        |
| You need a thread/job executor binary     | `wKernelExecutor`   |

---

## `composer.json`

```json
{
    "name": "flytachi/winter",
    "type": "project",
    "scripts": {
        "post-create-project-cmd": [
            "chmod -R 777 storage",
            "@php call cfg init"
        ],
        "dev-server": "@php -S 0.0.0.0:8000 -t ./public"
    },
    "autoload": {
        "psr-4": { "Main\\": "main/" }
    },
    "require": {
        "php": ">=8.3",
        "flytachi/winter-kernel": "^3.0"
    }
}
```

Key points:

- **`type: project`** — not a library; it's an application scaffold.
- **One PSR-4 prefix** (`Main\\` → `main/`) — add more as you split
  things into modules. The kernel's `Scanner` and `Router` follow
  every PSR-4 root, so any additional namespace is auto-discovered.
- **`post-create-project-cmd`** delegates the heavy lifting to
  `cfg init` (see [`console/03-cfg.md`](console/03-cfg.md#cfg-init)) so
  the recipe stays single-source.

---

## `bootstrap.php` — the `Boot` class

The whole framework configuration lives in **one file**: a class that
extends `BaseBoot` and overrides only the hooks you need. Hooks are
called in a fixed order from every entry point.

### Hook order

```
1. configure()        ← Kernel::init() — paths, .env, logging, timezone
2. DI scan            ← auto-discovers #[Singleton] / #[Request] / #[Transient]
3. providers($c)      ← manual bindings, factories, scalar values
4. channels()         ← extra log channels beyond sys / http / cli
5. plugins()          ← route-prefixed sub-applications
6. httpCors()         ← global CORS policy
7. health()           ← /actuator endpoints
```

### Minimal `Boot`

```php
<?php
declare(strict_types=1);

use Flytachi\Winter\DI\Container;
use Flytachi\Winter\K2\BaseBoot;
use Flytachi\Winter\K2\Http\Cors;
use Flytachi\Winter\K2\Http\Health\Health;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Plugin;

require __DIR__ . '/vendor/autoload.php';

class Boot extends BaseBoot
{
    protected static function configure(): void
    {
        Kernel::init();
    }

    // All other hooks are optional — defaults are sane no-ops.
}
```

### Full template (every hook documented)

```php
class Boot extends BaseBoot
{
    /**
     * Kernel — paths, .env, logging, timezone.
     *
     * Kernel::init() accepts overrides for every path; omit them to
     * derive from $pathRoot:
     *   pathRoot            project root              (default: cwd)
     *   pathEnv             .env file                 ($pathRoot/.env)
     *   pathPublic          web-accessible dir        ($pathRoot/public)
     *   pathResource        view / template dir       ($pathRoot/resources)
     *   pathStorage         writable storage root     ($pathRoot/storage)
     *   pathStorageLog                                ($pathStorage/logs)
     *   pathStorageCache                              ($pathStorage/cache)
     *   pathStorageRunnable                           ($pathStorage/runnable)
     */
    protected static function configure(): void
    {
        Kernel::init();
    }

    /**
     * DI — service providers, manual bindings, scalar values.
     */
    protected static function providers(Container $c): void
    {
        // $c->register(AppServiceProvider::class);
        // $c->singleton(CacheInterface::class, RedisCache::class);
        // $c->bind(MailerInterface::class, fn(Container $c) =>
        //     new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
        // );
        // $c->set('config.timeout', (int) env('APP_TIMEOUT', 30));
    }

    /**
     * Logging — extra channels beyond sys / http / cli.
     * Each channel reads LOG_{NAME}_* env with the same fallback chain.
     */
    protected static function channels(): void
    {
        // Kernel::channel('job');
        // Kernel::channel('daemon');
    }

    /**
     * CORS — global policy, applied to every response (incl. 404/500).
     * Per-route overrides via #[CrossOrigin].
     */
    protected static function httpCors(): void
    {
        // Cors::configure(
        //     origins:      ['https://app.example.com'],
        //     allowHeaders: ['Content-Type', 'Authorization'],
        //     credentials:  true,
        //     maxAge:       3600,
        // );
    }

    /**
     * Health — diagnostic endpoints under /actuator.
     *   /actuator            full report
     *   /actuator/health     up | degraded | down
     *   /actuator/info       PHP / SAPI / framework meta
     *   /actuator/metrics    CPU / memory / disk / opcache / uptime
     *   /actuator/env        custom env values
     *   /actuator/loggers    active channels and levels
     *   /actuator/mappings   registered route table
     */
    protected static function health(): void
    {
        // Health::configure();
        // Health::configure(
        //     indicator:  App\Health\AppHealthIndicator::class,
        //     middleware: App\Http\Middleware\InternalOnlyMiddleware::class,
        // );
    }

    /**
     * Plugins — route-prefixed sub-applications. Each plugin's src/
     * is scanned for controllers automatically.
     */
    protected static function plugins(): void
    {
        // Plugin::registry('acme/auth',    '/auth');
        // Plugin::registry('acme/billing', '/billing');
    }
}
```

Every hook is `protected static` — override only what you need. The
defaults are no-ops or sane fallbacks. For deep reference, see
[`configuration/01-kernel.md`](configuration/01-kernel.md).

---

## Entry points

Each runtime has its own one-liner:

| File                    | Runtime               | Body              |
|-------------------------|-----------------------|-------------------|
| `public/index.php`      | PHP-FPM / built-in dev| `Boot::web()`     |
| `server.php` (optional) | Swoole HTTP server    | `Boot::swoole()`  |
| `call`                  | CLI                   | `Boot::cli($argv)`|
| `wKernelExecutor` (optional) | Thread / job runner | `Boot::executor($argv)` |

### `public/index.php`

```php
<?php
require '../bootstrap.php';
Boot::web();
```

That's the entire FPM entry. `Boot::web()` runs the full request
lifecycle: configure → DI scan → providers → channels → plugins →
CORS → health → switch log channel to `http` → `Router::resolve()` →
`Router::static()` → `Router::handle()`.

### `call`

```php
#!/usr/bin/env php
<?php
if (PHP_VERSION_ID >= 80300) {
    chdir(__DIR__);
    require './bootstrap.php';
    Boot::cli($argv);
} else {
    echo "Please use PHP version 8.3 or higher.\n";
}
```

Three things to note:

1. **PHP version guard** — fail fast with a clear message instead of a
   parse error on older runtimes.
2. **`chdir(__DIR__)`** — pins CWD to the project root so the `.env`
   lookup and relative paths inside `bootstrap.php` resolve correctly
   no matter where you invoke `call` from.
3. **`Boot::cli($argv)`** — dispatches to `console/Command/*` (see
   [`console/00-overview.md`](console/00-overview.md)).

Make sure it's executable: `chmod +x call`.

---

## `.env`

Minimal starter:

```dotenv
WINTER_KEY=72aedae44ec31dec144eb297bdfcf64005b250b0562f428ab50bdd9da2e521d2
TIME_ZONE=UTC
DEBUG=true

LOG_LEVEL=info
LOG_FORMAT=line
LOG_OUTPUT=auto
#LOG_FILE=/var/log/app/winter.log
LOG_FILE_MAX=30
LOG_SYSLOG_IDENT=winter
```

Variables:

| Variable           | Effect                                                                |
|--------------------|------------------------------------------------------------------------|
| `WINTER_KEY`       | 32-byte project secret — signing keys, tokens                          |
| `TIME_ZONE`        | `date_default_timezone_set()` source                                   |
| `DEBUG`            | `true` — disables route + DI caches (always live scan), surfaces stack traces in logs and exception responses |
| `LOG_LEVEL`        | Minimum severity: `DEBUG / INFO / NOTICE / WARNING / ERROR / …`. **Empty → logging disabled** (NullLogger on all channels) |
| `LOG_FORMAT`       | `line` or `json`                                                       |
| `LOG_OUTPUT`       | `auto / stdout / stderr / syslog / file / null` — `auto` picks syslog in Docker/K8s, `stdout` under Swoole, `stderr` under FPM/CLI |
| `LOG_FILE`         | Absolute path when `LOG_OUTPUT=file`                                   |
| `LOG_FILE_MAX`     | Number of daily-rotating files to keep                                 |
| `LOG_SYSLOG_IDENT` | Program identity tag in syslog (`journalctl -t winter`)                |

For per-channel overrides (`LOG_HTTP_*`, `LOG_CLI_*`, custom channels)
see [`configuration/02-logging.md`](configuration/02-logging.md).

**Regenerate the key** any time:

```bash
php call cfg key -g
```

---

## `main/MainController.php`

The starter ships one controller as a smoke test:

```php
<?php
namespace Main;

use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

class MainController extends Controller
{
    #[RequestMapping]
    public function hello(): ResponseEntity
    {
        return ResponseEntity::ok('Hello');
    }
}
```

`#[RequestMapping]` with no path defaults to `GET /`. The route is
discovered automatically — no registration needed. Add more
controllers anywhere under `main/` and they'll appear in `call mapping
show`.

See [`architecture/01-routing.md`](architecture/01-routing.md).

---

## `.gitignore`

```gitignore
## IDE
.idea
.vscode
.fleet
.nova
.zed
.DS_Store
.phpunit.result.cache

## App
/.env
/vendor
```

`/storage/cache/*` and friends are kept tracked via per-dir `.gitignore`
files installed by `call storage init` (which keeps the folder
committed but ignores its contents). See
[`console/08-storage.md`](console/08-storage.md).

---

## First-run checklist

After cloning a starter-shaped project on a fresh machine:

```bash
composer install                 # pull kernel + deps
chmod -R 777 storage             # if not already (post-create-project-cmd handles fresh installs)
chmod +x call                    # if not already

php call cfg env -i              # if .env is missing
php call cfg key -g              # regenerate WINTER_KEY
php call storage init            # ensure storage/{cache,logs}/ exist

php call run dev                 # 0.0.0.0:8000  (PHP built-in)
# or
php call run                     # Swoole (requires pecl install swoole)
```

For production, also bake the caches into your image:

```bash
php call mapping build           # write route cache
php call di build                # write DI cache
```

See [`console/07-mapping.md`](console/07-mapping.md) and
[`console/10-di.md`](console/10-di.md).

---

## Building your own starter

Anything in `/Users/flytachi/Documents/Workspace/winter` you can
recreate in 5 files. The minimum a Winter project needs is:

| File                  | Lines |
|-----------------------|-------|
| `composer.json`       | ~10 (one PSR-4 root + `flytachi/winter-kernel`) |
| `bootstrap.php`       | ~10 (autoload + minimal `Boot` with `configure()`) |
| `public/index.php`    | 2     |
| `call`                | ~10 (PHP guard + `Boot::cli`) |
| `.env`                | 1 (`WINTER_KEY=...`) |

Run `composer install`, `php call cfg init`, and you have a working
project. Add controllers under any PSR-4 root and they're served.

---

## Source / references

- **Starter repo**: <https://github.com/flytachi/winter>
- **Kernel**: <https://github.com/flytachi/winter-kernel>
- **`BaseBoot`**: `vendor/flytachi/winter-kernel/src/BaseBoot.php`
- **`Kernel::init()`**: `vendor/flytachi/winter-kernel/src/Kernel.php`

## See also

- [`configuration/01-kernel.md`](configuration/01-kernel.md) — every `Boot` hook in depth
- [`configuration/02-logging.md`](configuration/02-logging.md) — `LOG_*` env reference
- [`configuration/03-cors.md`](configuration/03-cors.md) — global vs per-route CORS
- [`configuration/04-health.md`](configuration/04-health.md) — `/actuator` setup
- [`configuration/05-plugins.md`](configuration/05-plugins.md) — `Plugin::registry()`
- [`configuration/06-db.md`](configuration/06-db.md) — DB config classes
- [`console/00-overview.md`](console/00-overview.md) — the `call` CLI
- [`console/03-cfg.md`](console/03-cfg.md) — `cfg init` / `key` / `env` / `docker`
- [`architecture/01-routing.md`](architecture/01-routing.md) — controller discovery
