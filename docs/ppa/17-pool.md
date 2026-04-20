# Pool — Connection Pool

`PpaConnectionPool` is the unified connection manager for both **FPM** and **Swoole** runtimes.
It replaces CDO's `ConnectionPool` with a driver-agnostic, pool-aware alternative.

---

## How it works

| Runtime | Behaviour |
|---------|-----------|
| **FPM** | One `CDO` per config class per process. Reused for the entire request. |
| **Swoole** | One `Swoole\ConnectionPool` (backed by `Swoole\Coroutine\Channel`) per config class. Connections are borrowed on first `db()` call inside a coroutine and automatically returned via `defer` when the coroutine ends. |

Broken connections in Swoole mode: pass `null` to `Swoole\ConnectionPool::put()` — the pool discards and recreates the slot automatically.

---

## Making a config pool-aware

By default every config class gets **1 connection** (safe, consistent with FPM).
To increase the pool size implement `PpaPoolConfigInterface` via `PpaPoolTrait`:

```php
use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolConfigInterface;
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolTrait;

class AppDb extends PgDbConfig implements PpaPoolConfigInterface
{
    use PpaPoolTrait;

    public int   $poolMaxConnections = 10;  // max simultaneous CDO connections
    public float $poolWaitTimeout    = 5.0; // seconds to wait before PpaPoolException

    public function setUp(): void
    {
        $this->host     = env('DB_HOST', 'localhost');
        $this->port     = (int) env('DB_PORT', 5432);
        $this->database = env('DB_NAME', 'app');
        $this->username = env('DB_USER', 'postgres');
        $this->password = env('DB_PASS', '');
    }
}
```

---

## API

### `PpaConnectionPool::db(string $configClass): CDO`

Returns an active `CDO` for the given config class.
- FPM: process-level singleton.
- Swoole: borrows from the pool on first call per coroutine, auto-releases on coroutine end.

```php
use Flytachi\Winter\K2\Ppa\Pool\PpaConnectionPool;

$cdo = PpaConnectionPool::db(AppDb::class);
$rows = $cdo->query("SELECT * FROM users")->fetchAll();
```

### `PpaConnectionPool::getConfigDb(string $configClass): DbConfigInterface`

Returns the initialised (after `setUp()`) config instance.
Cached after first access — `setUp()` is called only once per config class.

### `PpaConnectionPool::showDbConfigs(): DbConfigInterface[]`

Returns all registered config instances. Used internally by Health checks.

---

## `PpaPoolConfigInterface`

```php
interface PpaPoolConfigInterface
{
    public function getPoolMaxConnections(): int;   // default via trait: 5
    public function getPoolWaitTimeout(): float;    // default via trait: 3.0
}
```

### `PpaPoolTrait`

Drop-in implementation of `PpaPoolConfigInterface`.
Does **not** declare properties — define `$poolMaxConnections` and `$poolWaitTimeout`
directly on your class to override the defaults.

| Property | Type | Default |
|----------|------|---------|
| `$poolMaxConnections` | `int` | `5` |
| `$poolWaitTimeout` | `float` | `3.0` |

---

## Exceptions

`PpaPoolException` is thrown when:
- Pool is exhausted and `$poolWaitTimeout` is exceeded.
- Connection factory throws during slot creation.

```php
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolException;

try {
    $cdo = PpaConnectionPool::db(AppDb::class);
} catch (PpaPoolException $e) {
    // log and return 503
}
```
