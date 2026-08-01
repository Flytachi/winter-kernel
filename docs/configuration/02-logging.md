# Logging

Winter uses [**winter-logger**](https://github.com/flytachi/winter-logger) — a thin PSR-3 layer
over [Monolog](https://github.com/Seldaek/monolog) designed for multi-runtime PHP applications
(FPM, Swoole, CLI).

---

## How it works

```
Kernel::init()
    └── LoggerManager           ← built once, holds all channel configs
            ├── channel 'http'  ← HTTP request lifecycle (coroutine-isolated)
            └── channel 'sys'   ← everything else: kernel, CLI, background components

Entry point (index.php / call / run)
    └── LoggerFactory::setDefaultChannel('http' | 'sys')

Application code
    └── LoggerFactory::getLogger(MyClass::class)
            └── Logger (wraps Monolog, merges class FQCN into every record)
```

**Key principle — entry-point driven.** The kernel registers both channels and sets `sys` as
the default. Only the HTTP request path switches to `http`; everything else stays on `sys`:

| Entry point | Channel | Context storage |
|-------------|---------|-----------------|
| `public/index.php` (FPM) | `http` | `ProcessContext` (per FPM worker) |
| `call run` — request workers | `http` | `CoroutineContext` (per coroutine) |
| `call run` — master + components (Process/Daemon/Scheduler) | `sys` | `ProcessContext` (per process) |
| `call` (CLI commands) | `sys` | `ProcessContext` (per process) |
| `wKernelRunner` (detached processes) | `sys` | `ProcessContext` |

---

## Default behavior

When no `LOG_*` variables are set in `.env` (or `LOG_LEVEL` is empty), the kernel registers
all channels with a `NullHandler` — every log call is accepted by the PSR-3 interface but
**discarded silently**. Nothing is written anywhere, no errors are thrown.

The same applies when [monolog/monolog](https://github.com/Seldaek/monolog) is not installed —
`LoggerManager` detects the missing class and returns a `NullLogger` for every channel:

```
LOG_LEVEL not set  →  NullHandler on all channels  →  all log calls discarded
monolog not installed  →  NullLogger on all channels  →  all log calls discarded
```

This means logging is **opt-in**: the application boots and runs correctly without any log
configuration. To enable it, add a single line to `.env`:

```dotenv
LOG_LEVEL=info
```

---

## Configuration (.env)

All logging is configured via `.env` — no code changes are needed for basic setup.

### Global defaults

These apply to every channel unless a per-channel override is set.

| Variable | Default | Description |
|----------|---------|-------------|
| `LOG_LEVEL` | *(empty)* | Minimum severity to record. **Empty → logging disabled** (NullLogger). |
| `LOG_FORMAT` | `line` | Output format: `line` or `json`. |
| `LOG_OUTPUT` | `auto` | Destination (see table below). |
| `LOG_FILE` | *(auto)* | Absolute file path when `LOG_OUTPUT=file`. |
| `LOG_FILE_MAX` | `30` | Number of daily rotating files to keep. |

#### `LOG_LEVEL` values

`DEBUG` · `INFO` · `NOTICE` · `WARNING` · `ERROR` · `CRITICAL` · `ALERT` · `EMERGENCY`

Case-insensitive. Records below the configured level are discarded before reaching any handler.

#### `LOG_OUTPUT` values

| Value | Handler | When to use |
|-------|---------|-------------|
| `auto` | `php://stdout` | Default — always `stdout`; the orchestrator / supervisor / terminal captures it |
| `stdout` | `php://stdout` | Swoole workers, CLI tools with piped output |
| `stderr` | `php://stderr` | FPM — immune to broken-pipe on client disconnect |
| `syslog` | system syslog | Docker, Kubernetes (journald, `/var/log/syslog`) |
| `file` | rotating daily file | Long-running daemons, per-channel audit logs |
| `null` | `/dev/null` | Tests — discards everything silently |

> **FPM note:** use `stderr`, not `stdout`. FPM's stdout is the FastCGI response stream —
> a client disconnect triggers `SIGPIPE` and kills the worker. `stderr` writes to the error
> log independently of the client connection.

#### `LOG_FORMAT` values

| Value | Description |
|-------|-------------|
| `line` | Human-readable single line (see format section below) |
| `json` | Newline-delimited JSON — suited for Loki, Elasticsearch, Datadog |

---

### Per-channel overrides

Any global variable can be overridden for a specific channel by prefixing it with
`LOG_{CHANNEL}_` (channel name is uppercased automatically).
The per-channel value takes priority; if absent, the global value is used.

```dotenv
# Global baseline
LOG_LEVEL=info
LOG_OUTPUT=auto
LOG_FORMAT=line

# http channel — warnings only, separate file
LOG_HTTP_LEVEL=warning
LOG_HTTP_OUTPUT=file
LOG_HTTP_FILE=/var/log/app/http.log
LOG_HTTP_FILE_MAX=14

# sys channel — always goes to syslog
LOG_SYS_OUTPUT=syslog

# custom 'job' channel (registered via Kernel::channel('job'))
LOG_JOB_LEVEL=debug
LOG_JOB_OUTPUT=file
LOG_JOB_FILE=/var/log/app/job.log
LOG_JOB_FILE_MAX=7
```

---

## Custom channels

Built-in channels (`http`, `sys`) are registered automatically by `Kernel::init()`.
Add extra channels in `bootstrap.php` using `Kernel::channel()`:

```php
// bootstrap.php — after Kernel::init()
Kernel::channel('job');
Kernel::channel('daemon');
```

Each call reads `LOG_{NAME}_*` env vars with the same fallback chain as built-in channels.
Must be called **after** `Kernel::init()` and **before** the first log write on that channel.

---

## Log format

### `line` (default)

```
[datetime] [LEVEL] -channel- [pid]: message {json}
[datetime] [LEVEL] -channel- [pid] (ClassName): message {json}
```

The `[pid]` segment is the OS process id of the emitting process — always present,
so forked children (daemons, workers) are distinguishable from the parent. The
`(ClassName)` segment appears only when the logger was obtained via
`LoggerFactory::getLogger(MyClass::class)` or injected as `#[Autowired] LoggerInterface`.
Raw `channel()` calls omit it.

**Level labels (fixed width):**

| Level | Label |
|-------|-------|
| Debug | `DEBUG` |
| Info | `INFO ` |
| Notice | `NOTIC` |
| Warning | `WARN ` |
| Error | `ERROR` |
| Critical | `CRIT ` |
| Alert | `ALERT` |
| Emergency | `EMERG` |

**Examples:**

```
[2024-01-01 12:00:00] [INFO ] -http- [4821]: request handled {"request_id":"abc-123"}
[2024-01-01 12:00:00] [DEBUG] -http- [4821] (UserService): db query {"class":"App\\Service\\UserService","request_id":"abc-123"}
[2024-01-01 12:00:00] [WARN ] -http- [4821] (PaymentService): retry {"class":"App\\PaymentService","attempt":3}
[2024-01-01 12:00:00] [ERROR] -http- [4821] (OrderController): checkout failed {"class":"App\\OrderController","order_id":99}
[2024-01-01 12:00:00] [DEBUG] -sys- [5012] (MyJob): step done {"class":"App\\Job\\MyJob","job_id":"xyz"}
[2024-01-01 12:00:00] [INFO ] -sys- [4821]: kernel booted
```

### `json`

One JSON object per line — ready for log aggregators:

```json
{"message":"user created","context":{"id":42,"class":"App\\Service\\UserService"},"level":200,"level_name":"INFO","channel":"http","datetime":"2024-01-01T12:00:00+00:00","extra":{"request_id":"abc-123"}}
```

---

## Usage in application code

### Injected logger — `#[Autowired]` (recommended for DI-managed classes)

Controllers, services, jobs — anything the container builds — can simply declare a
typed `LoggerInterface` property. No `getLogger()` boilerplate, no `self::class`:

```php
use Psr\Log\LoggerInterface;
use Flytachi\Winter\DI\Attribute\Autowired;

class OrderController extends Controller
{
    #[Autowired]
    private LoggerInterface $logger;

    public function index(): void
    {
        $this->logger->info('order placed', ['total' => 99.0]);
        // → [INFO ] -http- [4821] (OrderController): order placed {"total":99,"class":"...OrderController"}
    }
}
```

**How it works.** The boot registers a [contextual binding](https://github.com/flytachi/winter-di)
for `Psr\Log\LoggerInterface` by default — the container resolves the injected
logger to `LoggerFactory::getLogger(<the consuming class>)`. So the logger is
automatically named after the class it lives in, with the same per-class
`(ClassName)` output you'd get from the factory — but injected by type.

**Override** the default in a `#[Configuration]` class — e.g. pin a channel or swap the
factory entirely (re-registering wins, since `contextual()` is last-write for the key):

```php
protected static function providers(Container $c): void
{
    $c->contextual(
        \Psr\Log\LoggerInterface::class,
        fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app', 'http'),
    );
}
```

> Tip: a true circular dependency between two injected services can be broken with
> `#[Lazy]` (a deferred proxy) — see winter-di's `#[Lazy]` attribute.

**Which approach to use:**

| Situation | Use |
|-----------|-----|
| Class built by the container (controller, service, job) | `#[Autowired] private LoggerInterface $logger` |
| Static context / object not resolved by DI | `LoggerFactory::getLogger(self::class)` |
| Quick one-off on the default channel | `Log::info(...)` facade |
| A specific non-default channel | `LoggerFactory::getLogger($this, 'job')` |

### Per-class logger via factory

For code **not** built by the container (static helpers, manual `new`), get a
logger explicitly:

```php
use Flytachi\Winter\Logger\LoggerFactory;

// Uses the default channel set by the entry point (e.g. 'http')
LoggerFactory::getLogger(UserService::class)->info('user created', ['id' => 42]);
LoggerFactory::getLogger($this)->warning('slow query', ['ms' => 850]);

// Explicit channel override (falls back to default if channel is not registered)
LoggerFactory::getLogger(MyJob::class, 'job')->debug('processing item', ['item_id' => 7]);
```

### `Log` facade — quick static shortcut

```php
use Flytachi\Winter\Logger\Log;

Log::debug('cache miss');
Log::info('user created', ['id' => $id]);
Log::warning('retrying', ['attempt' => 3]);
Log::error('payment failed', ['order' => $orderId]);
Log::critical('db connection lost');
Log::alert('disk usage above 90%');
```

Equivalent to `LoggerFactory::logger()->{level}(...)` — always writes to the current default channel.

### Raw channel logger

```php
LoggerFactory::channel('http')->warning('rate limit hit');
LoggerFactory::channel('sys')->debug('job started');
```

No `(ClassName)` in output. Throws `InvalidArgumentException` if the channel is not registered.

### Bound context per logger instance

```php
$log = LoggerFactory::getLogger(PaymentService::class)
    ->withContext(['order_id' => $orderId, 'user_id' => $userId]);

$log->info('payment started');   // carries order_id + user_id on every call
$log->info('payment confirmed'); // carries order_id + user_id on every call
```

---

## Context storage — per-request fields

Context storage lets you attach fields once and have them appear in **every** log record
for the current request/coroutine without passing them manually.

### FPM / CLI — `ProcessContext`

Set by the kernel. Each FPM worker or CLI process has its own storage.

```php
// In a middleware or bootstrap — runs once per request
$ctx = LoggerFactory::contextStorage();
$ctx->set('request_id', uniqid('', true));
$ctx->set('user_id', $auth->id());

// Every subsequent log call in this request automatically includes request_id + user_id
LoggerFactory::getLogger(OrderService::class)->info('order placed', ['total' => 99.0]);
// → [INFO ] -http- [4821] (OrderService): order placed {"total":99,"request_id":"abc","user_id":42,"class":"..."}
```

### Swoole — `CoroutineContext`

Set by `call run` before the server starts. Each coroutine (= each concurrent HTTP request)
gets fully isolated storage — fields set in one coroutine never leak into another.

```php
// Set once in the Swoole request handler (e.g. a middleware)
$ctx = LoggerFactory::contextStorage();
$ctx->set('request_id', $req->header['x-request-id'] ?? uniqid('', true));
```

No extra wiring needed — `call run` switches to `CoroutineContext` automatically before
accepting connections.

---

## Sensitive data masking

`SensitiveMaskingProcessor` redacts sensitive values before they reach any handler.
Matching is **case-insensitive** on keys; nested arrays are traversed recursively.

**Default masked keys:**
`password`, `passwd`, `secret`, `token`, `access_token`, `refresh_token`,
`api_key`, `apikey`, `authorization`, `auth`, `cookie`, `set-cookie`,
`credit_card`, `card_number`, `cvv`, `ssn`, `pin`

All matched values are replaced with `***`.

```php
use Flytachi\Winter\Logger\Processor\SensitiveMaskingProcessor;

// Default keys only
$processor = new SensitiveMaskingProcessor();

// Add extra keys
$processor = new SensitiveMaskingProcessor(['patient_id', 'insurance_number']);

// Attach to a channel after Kernel::init()
/** @var \Flytachi\Winter\Logger\Logger $channel */
$channel = LoggerFactory::channel('http');
$channel->monolog()->pushProcessor($processor);
```

**Example:**

```php
$logger->info('login attempt', [
    'username' => 'alice',
    'password' => 'hunter2',          // ← masked
    'meta'     => ['token' => 'jwt'], // ← nested, also masked
]);
// → [INFO ] -http- [4821]: login attempt {"username":"alice","password":"***","meta":{"token":"***"}}
```

---

## Disabling logging

Set `LOG_LEVEL` to an empty string (or omit it entirely) — the kernel registers all channels
with a `NullHandler` that discards everything at zero cost:

```dotenv
LOG_LEVEL=
```

No code changes needed. This is the recommended approach for test environments.

---

## Quick reference — .env template

```dotenv
# ── Logging ────────────────────────────────────────────────────────────────

# Global baseline (inherited by all channels)
LOG_LEVEL=info           # DEBUG | INFO | NOTICE | WARNING | ERROR | CRITICAL | ALERT | EMERGENCY
LOG_FORMAT=line          # line | json
LOG_OUTPUT=auto          # auto | stdout | stderr | syslog | file | null
LOG_FILE_MAX=30          # rotating daily files to keep (output=file only)

# Per-channel overrides (LOG_{CHANNEL}_* — channel uppercased)
# LOG_HTTP_LEVEL=warning
# LOG_HTTP_OUTPUT=file
# LOG_HTTP_FILE=/var/log/app/http.log

# LOG_SYS_OUTPUT=syslog

# Custom channels (registered via Kernel::channel('job') in bootstrap.php)
# LOG_JOB_LEVEL=debug
# LOG_JOB_OUTPUT=file
# LOG_JOB_FILE=/var/log/app/job.log
# LOG_JOB_FILE_MAX=7
```
