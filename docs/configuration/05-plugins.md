# Plugins

Plugins are regular Composer packages that contribute controllers, exception handlers, and DI services under a URL prefix. Each plugin registration is one line in your `Boot::plugins()` hook:

```php
Plugin::registry('acme/billing-plugin', '/billing');
```

After that, every `#[Controller]` discovered under `vendor/acme/billing-plugin/src/` is mounted under `/billing/...`. No extra wiring needed.

---

## Registering a plugin

Override `plugins()` in your `Boot` class:

```php
use Flytachi\Winter\K2\Plugin;

class Boot extends BaseBoot
{
    protected static function plugins(): void
    {
        Plugin::registry('acme/auth-plugin',    '/auth');
        Plugin::registry('acme/billing-plugin', '/billing');
    }
}
```

### `Plugin::registry()` parameters

```php
public static function registry(
    string $package,
    string $prefix,
    bool   $required = true,
): void
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `package` | `string` | — | Composer package name (e.g. `acme/billing-plugin`). Resolved via `Composer\InstalledVersions::getInstallPath()`. |
| `prefix` | `string` | — | URL prefix mounted in front of every plugin route. Leading/trailing slashes are normalised — `/billing`, `billing/`, and `billing` all produce the prefix `/billing`. |
| `required` | `bool` | `true` | When `true`, throws `Error` if the package is not installed. Set to `false` for optional plugins. |

Throws when:

- the package is not installed and `$required = true` (`Plugin '$package' has no install path`)
- the same prefix is registered twice (`Plugin prefix '$prefix' already registered`)

Source: `src/Plugin.php`.

---

## How a plugin is loaded

Plugin registration runs **before** the route scan. During `Router::fromScan()` (or the first request after a clean cache):

```
1. Boot::plugins() runs            ← Plugin::registry() entries collected
2. Router::fromScan(pathRoot)      ← scans your application src/
3. For each registered plugin:
     scan vendor/<package>/src/     ← MappingCollector with prefix
4. Health::configure() endpoints   ← only the app-level ones
5. Cache compiled routes           ← if DEBUG=false
```

The plugin's `src/` is scanned by the same `Scanner` + `MappingCollector` pipeline as your own controllers — same `#[GetMapping]`, `#[Controller]`, `#[Autowired]`, and `#[AdviceException]` semantics apply.

Source: `src/Route/Router.php` (`fromScan()`).

---

## What a plugin can contribute

A plugin contributes everything the Scanner picks up from its `src/`:

| Discovered | Behaviour under plugin |
|---|---|
| `#[Controller]` + `#[GetMapping]` / `#[PostMapping]` / … | Mounted under the plugin prefix |
| `#[RequestMapping]` on a class | Concatenated **after** the plugin prefix |
| `#[CrossOrigin]` | Works per-route as usual |
| `#[Autowired]` services (`#[Singleton]`, `#[Request]`, `#[Transient]`) | Registered into the shared `Container` |

What plugins do **not** get to do:

- Register their own `#[AdviceException]` handlers — only the main app's `ExceptionCollector` runs. (Plugin handler scanning could be added later, but is not wired today.)
- Mount routes outside their prefix — every URL is forced under `/<prefix>/...`.
- Override the app-level `Cors::configure()` — they can only use `#[CrossOrigin]` on their own controllers.

---

## URL composition

A plugin controller's final URL is `prefix + class_prefix + method_path`:

```php
// In vendor/acme/billing-plugin/src/Http/InvoiceController.php
#[RequestMapping('invoices')]
class InvoiceController extends Controller
{
    #[GetMapping('{id:\d+}')]
    public function show(#[PathVariable] int $id): ResponseEntity { ... }
}
```

Registered with `Plugin::registry('acme/billing-plugin', '/billing')`, the final URL is:

```
GET /billing/invoices/42
    └──────┘└──────┘└──┘
    prefix  class    method
```

Without `#[RequestMapping]` on the class, the URL is `<prefix>/<method-path>`.

---

## Optional plugins

When a plugin is conditionally installed (e.g. enterprise add-ons, optional dev tools), set `required: false` to skip registration silently when the package is absent:

```php
protected static function plugins(): void
{
    Plugin::registry('acme/core-plugin',  '/core');
    Plugin::registry('acme/admin-plugin', '/admin', required: false);
}
```

If `acme/admin-plugin` is not in `composer.json`, `Plugin::registry()` returns without error and no routes are mounted.

---

## Inspecting registered plugins

`Plugin::getPlugins()` returns the prefix → install-path map, useful for diagnostics:

```php
foreach (Plugin::getPlugins() as $prefix => $path) {
    LoggerFactory::getLogger()->info('plugin', ['prefix' => $prefix, 'path' => $path]);
}
```

This map is also what `Router::fromScan()` walks during the route scan.

---

## Authoring a plugin

A plugin is an ordinary Composer package. Minimum structure:

```
vendor/acme/billing-plugin/
├── composer.json
└── src/
    └── Http/
        └── InvoiceController.php
```

The plugin's `composer.json` declares its PSR-4 autoload root, just like an application:

```json
{
    "name": "acme/billing-plugin",
    "autoload": {
        "psr-4": {
            "Acme\\Billing\\": "src/"
        }
    }
}
```

That is the entire contract. Anything else is plain PHP — services, DTOs, middleware, exception classes — and is picked up by the same DI scan as the application's own classes.

> **Note.** Because the Scanner walks `vendor/<package>/src/` only when `Plugin::registry()` is called, vendor code that is **not** registered as a plugin is **not** scanned. Routes and DI services in unregistered packages stay invisible to the framework.

---

## Related

| Topic | File |
|---|---|
| Bootstrap hooks order — when `plugins()` runs | [01-kernel.md](01-kernel.md#boot-order) |
| Routing pipeline & `Router::fromScan()` | [../architecture/01-routing.md](../architecture/01-routing.md#factory-methods) |
| DI scanning (`#[Autowired]` etc.) | `flytachi/winter-di` package docs |
