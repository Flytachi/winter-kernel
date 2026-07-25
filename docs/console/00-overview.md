# Winter Console — Overview

The Winter console is the CLI surface of the kernel. Every project gets a
single binary — `call` — that dispatches to a small set of built-in commands
(scaffolding, config, runtime, mapping, DB, threads…) and to any custom
command the project or its plugins register.

The entry point on disk is `wKernelExecutor`, which boots the kernel and
hands `$argv` to `Boot::executor()`. In a typical project the user-facing
binary is `call` (a project script, alias, or symlink to the executor);
the rest of this section uses `call` as the canonical invocation.

---

## Anatomy of an invocation

```
call <command> [sub] [arguments...] [-flags] [--options[=value]]
```

`CoreHandle::parser()` (`console/Inc/CoreHandle.php`) splits `$argv` into
three buckets:

| Bucket       | Source              | Example                          |
|--------------|---------------------|----------------------------------|
| `arguments`  | positional tokens   | `call make .User` → `make`, `.User` |
| `flags`      | `-x` (single chars) | `-csre` → `c`, `s`, `r`, `e`     |
| `options`    | `--key` or `--key=v`| `--port=8000`, `--mvc`           |

The first positional argument is the command name; if omitted, `Help` runs.
Short aliases are wired in `console/Core.php`:

| Alias  | Resolves to |
|--------|-------------|
| `sc`   | `Script`    |
| `th`   | `Thread`    |
| `proc` | `Process`   |
| `dmn`  | `Daemon`    |
| `sch`  | `Schedule`  |

A command name is mapped to `Flytachi\Winter\Console\Command\<Name>` via
`ucwords()`, and `::script($parsed)` is called on it.

---

## Anatomy of a command class

Every built-in command lives in `console/Command/` and extends `Cmd`:

```php
namespace Flytachi\Winter\Console\Command;

use Flytachi\Winter\Console\Inc\Cmd;

class Example extends Cmd
{
    public static string $title = "what this command does";

    public function handle(): void
    {
        // runtime — read $this->args['arguments'|'flags'|'options']
    }

    public static function help(): void
    {
        // printed by `call help example` and by `-h` / `--help`
    }
}
```

Three contract points:

| Member            | Role                                                     |
|-------------------|----------------------------------------------------------|
| `static $title`   | one-line summary in `call help` listing                  |
| `handle()`        | the actual work                                          |
| `static help()`   | detailed usage block (printed by `call help <cmd>` and `-h`) |

### Lifecycle inside `Cmd::script()`

```
Container::make(static::class, ['args' => $args])  ← DI-instantiated
  ↓
$instance->init()    ← optional hook (override in subclass)
  ↓
$instance->isHelp()  ← short-circuits to ::help() if -h / --help present
  ↓
$instance->handle()  ← the command body
```

Any `\Throwable` is caught and rendered through `Printer::printError()`
so commands never spill PHP stack traces to the user.

---

## DI in commands

`Cmd::script()` instantiates through `Container::make()`, so constructor
injection and `#[Autowired]` properties work the same as in controllers
and services:

```php
class Sync extends Cmd
{
    public function __construct(
        array $args,
        private MyService $service,   // resolved by the container
    ) {
        parent::__construct($args);
    }
}
```

The same applies to `CmdCustom` (see below).

---

## `Cmd` vs `CmdCustom`

| Aspect              | `Cmd` (built-in)                          | `CmdCustom` (user)         |
|---------------------|-------------------------------------------|----------------------------|
| `$title`            | required (shown in `call help`)           | not used                   |
| `help()`            | required (`-h` / `--help` dispatch)       | not used                   |
| Discovered by `help`| yes                                       | no                         |
| Runnable directly   | `call <name>`                             | `call sc <dot.Path.Class>` |
| Use for             | first-class commands shipped by framework | one-off project scripts    |

User scripts go through the `script` command (`call sc`), which resolves
dot-notation to a class and runs `::script()` on it. See
[05-script.md](05-script.md).

---

## Output (`Printer`)

All commands write through `Printer` static helpers — they produce
ANSI-colored output with a consistent bar/padding format:

| Helper              | Use                                            |
|---------------------|------------------------------------------------|
| `printTitle()`      | wrapped section banner                         |
| `printLabel()`      | block label `[ Foo ]`                          |
| `printKeyValue()`   | aligned key → value pair                       |
| `printBadge()`      | `name ........ [STATUS]` row                   |
| `printStep()`       | `[3/12] message` progress indicator            |
| `printDivider()`    | dotted separator                               |
| `printList()`       | bulleted list                                  |
| `printInfo()`       | `[i]` informational line (cyan)                |
| `printSuccess()`    | `[✓]` success line (green)                     |
| `printWarning()`    | `[!]` warning line (yellow)                    |
| `printError()`      | exception block + `die()` — terminal           |

Don't `echo` directly — use the helpers so output stays uniform across
the whole CLI.

---

## Help & shell completion

- `call help` — list every discovered command + environment info
- `call help <cmd>` — dispatch to that command's `static help()`
- `call <cmd> -h` / `--help` — same `help()` block, via `isHelp()`
- `call cfg completion -i` — install zsh/bash tab-completion globally
  (writes `~/.zsh/completions/_call` or `~/.bash_completion.d/call`)

The `complete` command (`call complete`) is the internal endpoint that
the completion script calls; it produces filtered suggestion lines from
the map declared in `console/Command/Complete.php`. End users do not run
it directly. See [11-complete.md](11-complete.md).

---

## Built-in command catalogue

| Page | Command  | Purpose                                                |
|------|----------|--------------------------------------------------------|
| [01](01-help.md)     | `help`    | List commands and show usage                        |
| [02](02-make.md)     | `make`    | Generate component skeletons                        |
| [03](03-cfg.md)      | `cfg`     | Manage configuration, `.env`, key, Docker, completion |
| [04](04-run.md)      | `run`     | Start the HTTP server (Swoole / dev)                |
| [05](05-script.md)   | `script`  | Run custom `Cmd` / `CmdCustom` scripts (alias `sc`) |
| [06](06-db.md)       | `db`      | Database ping / migrate / SQL preview               |
| [07](07-mapping.md)  | `mapping` | Build / clean / show route cache                    |
| [08](08-storage.md)  | `storage` | Initialize and clean `storage/` folders             |
| [09](09-thread.md)   | `thread`  | Run `Dispatchable` tasks (alias `th`)               |
| [10](10-di.md)       | `di`      | Build / clean / show DI scanner cache               |
| [11](11-complete.md) | `complete`| Shell-completion endpoint (internal)                |
| [12](12-schedule.md) | `schedule`| Run the scheduler; list `#[Scheduled]` tasks (alias `sch`) |

The **`process`** and **`daemon`** commands (aliases `proc` / `dmn`) manage
long-lived worker processes and supervised fleets; they are documented with the
runtime itself in [`process/03-control.md`](../process/03-control.md) and
[`process/daemon/03-control.md`](../process/daemon/03-control.md).

---

## Adding your own command

1. Pick the base class:
   - `Cmd` if you want it listed by `call help` and reachable as
     `call <name>` directly. Put it under a namespace that is mapped
     PSR-4 and named `Flytachi\Winter\Console\Command\<Name>`-compatible,
     **or** ship it as `CmdCustom` and run via `call sc`.
   - `CmdCustom` for project scripts that don't deserve top-level
     visibility (run via `call sc <dot.Class>`).
2. Scaffold it: `call make .MyCmd -n` produces a `Cmd` skeleton.
3. Implement `handle()` (and `static help()` for `Cmd`).
4. Add DI dependencies to the constructor — they resolve automatically.

`call sc list` discovers every concrete `Cmd` / `CmdCustom` under the
project's PSR-4 roots (and registered plugins) at runtime, so no manual
registration is needed.
