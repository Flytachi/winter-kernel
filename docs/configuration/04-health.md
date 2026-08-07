# Health / Actuator

Read-only diagnostic endpoints under `/actuator/*` — modelled after Spring Boot Actuator. Disabled by default; opt in with `#[EnableActuator]` on the application class.

The endpoints are useful for:

- Kubernetes / load-balancer liveness and readiness probes.
- Monitoring agents (Prometheus, Datadog scrapers).
- On-call debugging (active log channels, registered routes, current metrics).

---

## Enabling

Override `health()` in your `Boot` class and call `Health::configure()`:

```php
use Flytachi\Winter\Kernel\App\Attribute\EnableActuator;
use Flytachi\Winter\Kernel\WinterApplication;

#[EnableWeb]
#[EnableActuator]                  // default built-in indicator, open access
final class Application extends WinterApplication { /* ... */ }
```

The attribute accepts a custom indicator and a guard middleware:

```php
#[EnableActuator(
    indicator:  App\Health\AppHealthIndicator::class,
    middleware: App\Http\Middleware\InternalOnlyMiddleware::class,
)]
```

To restrict access or replace the indicator, pass arguments:

```php
protected static function health(): void
{
    Health::configure(
        indicator:  App\Health\AppHealthIndicator::class,
        middleware: App\Http\Middleware\InternalOnlyMiddleware::class,
    );
}
```

### `Health::configure()` parameters

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `indicator` | `class-string<HealthIndicatorInterface>` | `HealthIndicator::class` | Provides the data returned by each endpoint. |
| `middleware` | `class-string<Middleware>` or `null` | `null` | Optional guard middleware (e.g. IP allowlist, auth). |

When `health()` is not overridden, no actuator routes are registered.

Source: `src/Http/Health/Health.php`.

---

## Endpoints

All endpoints are `GET` and return JSON. The Router exposes two routes that share one handler:

| Route | Returns |
|---|---|
| `GET /actuator` | Same as `GET /actuator/health` |
| `GET /actuator/{method}` | Calls `$indicator->{method}()` |

The indicator method name is taken directly from the URL segment, so the supported endpoints are exactly the methods on `HealthIndicatorInterface`:

| URL | Method | Description |
|---|---|---|
| `/actuator/health` | `health()` | Overall status (`up` / `degraded` / `down`) + per-component breakdown |
| `/actuator/info` | `info()` | PHP version, SAPI, framework version, project meta from `composer.json` |
| `/actuator/metrics` | `metrics()` | CPU load, memory, disk, system info, opcache, current request, uptime |
| `/actuator/env` | `env()` | Custom env values — empty by default; override in your indicator |
| `/actuator/loggers` | `loggers()` | Active log channels and their configured levels / output / file path |
| `/actuator/mappings` | `mappings()` | Snapshot of registered routes (built once at boot) |

Calling an unknown method (e.g. `/actuator/foobar`) returns `404 Not Found` via `ResponseException`.

> `GET /actuator` is **not** an aggregated dump — it falls through to `health()` because the handler defaults `$method = $params['method'] ?? 'health'`. To get all sections in one response, call them individually or build a custom `dump()` method on your indicator.

Source: `src/Route/Router.php` (`registerHealth()`).

---

## Status semantics

`health()` returns `up` / `degraded` / `down` for each component:

```json
{
  "status": "degraded",
  "components": {
    "db":     {"status": "up",       "details": {...}},   // per datasource, pool nested inside
    "cache":  {"status": "up",       "details": {...}},
    "disk":   {"status": "degraded", "details": {"usage_percent": 85.4, "warning": "Disk usage above 80%"}},
    "memory": {"status": "up",       "details": {...}},
    "custom": {"status": "up",       "details": {}}
  }
}
```

Aggregation rule: any `down` → overall `down`; otherwise any `degraded` → overall `degraded`; otherwise `up`.

### Response code

The overall status is also the response code, so a probe that reads the code rather than the body reaches the same verdict:

| Overall status | Code |
|---|---|
| `up` | `200` |
| `degraded` | `200` |
| `down` | `503 Service Unavailable` |

`degraded` stays `200` on purpose: it means working worse, not not working, and a readiness probe that pulled the instance out of rotation over it would turn a partial outage into a full one. The body is the same in either case — the code carries no information the report does not.

Only `/actuator/health` is affected. The other endpoints answer `200`, including one whose payload happens to contain a `status` key of its own.

This makes the endpoint usable directly as a container health check or a k8s probe:

```yaml
readinessProbe:
  httpGet: { path: /actuator/health, port: 8000 }
```

### Disk / memory thresholds

Built into `HealthIndicator`:

| Threshold | Status |
|---|---|
| ≥ 90 % usage | `down` |
| ≥ 80 % usage | `degraded` |
| < 80 % usage | `up` |

Memory percentage is calculated against `memory_limit` (ini value). When `memory_limit = -1` (unlimited), it is treated as 0 % usage.

### DB / cache health

Optional — only reports `up` and an empty `details` map unless one of these packages is installed alongside winter-kernel:

| Package | Interface scanned | What it reports |
|---|---|---|
| `flytachi/winter-cdo` | `Flytachi\Winter\Cdo\Config\Common\DbConfigInterface` | Per-config `status`, driver, ping latency, error message |
| `flytachi/winter-cache` | `Flytachi\Winter\Cache\Config\Common\RedisConfigInterface` | Per-config `status`, ping latency, error message |

`HealthIndicator` scans `Kernel::$pathRoot` for implementations and calls `pingDetail()` on each. A latency ≥ **500 ms** flips the component to `degraded`. Connection failure → `down`.

The `db` component carries **one entry per datasource**, holding both its reachability
and how loaded its connection pool is:

```json
"db": {
  "status": "up",
  "details": {
    "App\\Config\\AppDb": {
      "status": "up", "driver": "pgsql", "latency": 1.2, "error": null,
      "pool": {"total": 5, "idle": 3, "active": 2, "maximum": 10}
    }
  }
}
```

The two answer different questions — the ping says whether the database is reachable, the
pool says whether this worker has connections left — so they live together rather than in
separate components. A saturated pool (`active >= maximum`) degrades its datasource.
`pool` is `null` when the worker holds no pool for that config, and the numbers are **per
worker**: a health request reports the worker that served it. For the fleet-wide view use
[`call db pool`](../console/06-db.md).

---

## Authoring a custom indicator

Subclass `HealthIndicator` to add custom checks without losing the built-in disk/memory/db/cache logic. The cleanest extension point is `customHealth()`:

```php
namespace App\Health;

use Flytachi\Winter\Kernel\Http\Health\HealthIndicator;

final class AppHealthIndicator extends HealthIndicator
{
    protected function customHealth(): array
    {
        $queueLag = $this->measureQueueLag();
        $status   = $queueLag > 60 ? 'down'
                   : ($queueLag > 10 ? 'degraded' : 'up');

        return [
            'status'  => $status,
            'details' => ['queue_lag_seconds' => $queueLag],
        ];
    }

    public function env(): array
    {
        // Surface a curated, redacted slice of the environment
        return [
            'app_name'    => env('APP_NAME'),
            'app_version' => env('APP_VERSION'),
            'environment' => env('APP_ENV'),
        ];
    }
}
```

Then register it:

```php
Health::configure(indicator: AppHealthIndicator::class);
```

To replace the indicator entirely, implement `HealthIndicatorInterface` directly. The interface is small:

```php
interface HealthIndicatorInterface
{
    public function health(): array;
    public function info(): array;
    public function metrics(): array;
    public function env(): array;
    public function loggers(): array;
    public function mappings(): array;
}
```

---

## Guarding the endpoints

Production deployments usually want `/actuator/*` reachable only from inside the cluster. Pass a middleware class that performs the check:

```php
namespace App\Http\Middleware;

use Flytachi\Winter\Kernel\Http\Stereotype\Middleware;
use Flytachi\Winter\Kernel\Http\Contracts\{HttpRequest, HttpResponse};
use Flytachi\Winter\Kernel\Http\Middleware\MiddlewareException;
use Flytachi\Winter\Base\HttpCode;

final class InternalOnlyMiddleware extends Middleware
{
    public function before(HttpRequest $req, HttpResponse $res): void
    {
        $ip = $req->getHeader('x-forwarded-for') ?? $req->getRemoteAddress();
        if (!str_starts_with($ip, '10.') && !str_starts_with($ip, '127.')) {
            throw new MiddlewareException('Forbidden', HttpCode::FORBIDDEN);
        }
    }
}
```

```php
Health::configure(middleware: InternalOnlyMiddleware::class);
```

The middleware is invoked on both `/actuator` and `/actuator/{method}` before any handler code.

See [`../architecture/02-middleware.md`](../architecture/02-middleware.md) for middleware mechanics.

---

## Example responses

### `GET /actuator/info`

```json
{
  "php": {"version": "8.4.2", "sapi": "cli-server", "zend_version": "4.4.0"},
  "framework": {"name": "flytachi/winter-kernel", "version": "1.x-dev", "runtime": "Fpm"},
  "project": {"name": "acme/api", "type": "project", "version": "2.5.0", "isDev": false}
}
```

### `GET /actuator/metrics` (excerpt)

```json
{
  "cpu":            {"load_average": [0.42, 0.61, 0.55], "core_count": 8},
  "memory":         {"usage": 12582912, "peak": 14680064, "limit": 134217728},
  "disk":           {"free": 53687091200, "total": 107374182400, "usage_percent": 50.0},
  "system":         {"os": "Linux", "release": "6.7.0", "hostname": "api-01"},
  "php":            {"version": "8.4.2", "sapi": "cli-server", "zend_version": "4.4.0", "execution_time": 2.317},
  "opcache":        { /* opcache_get_status(false) */ },
  "requests":       {"method": "GET", "uri": "/actuator/metrics", "user_agent": "kube-probe/1.30"},
  "uptime_seconds": 184290
}
```

### `GET /actuator/loggers`

```json
{
  "http": {"level": "WARN",  "output": "file",   "format": "json", "file": {"path": "storage/logs/http.log", "max_files": 14}},
  "sys":  {"level": "INFO",  "output": "syslog", "format": "line"}
}
```

Level `"disabled"` appears when the channel has no `LOG_LEVEL` set — the channel uses `NullHandler` and discards everything.

---

## Related

| Topic | File |
|---|---|
| Logging — channels and `.env` reference | [02-logging.md](02-logging.md) |
| Middleware — used by the optional guard | [../architecture/02-middleware.md](../architecture/02-middleware.md) |
| Routing pipeline | [../architecture/01-routing.md](../architecture/01-routing.md) |
