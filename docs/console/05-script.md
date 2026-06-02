# `call script` (`call sc`) — run or list custom Cmd scripts

Bridge between the built-in CLI and user-defined commands. Resolves a
dot-notation class path to a fully qualified class, verifies it extends
`Cmd` or `CmdCustom`, and runs `::script()` on it.

Alias: **`sc`**.

---

## Synopsis

```
call script <dot.notation.ClassName> [arguments...] [-flags] [--options]
call sc     <dot.notation.ClassName> [arguments...] [-flags] [--options]
call sc list
```

---

## Description

`script` is how the project runs its **own** CLI code — anything that
doesn't ship inside `console/Command/` but still wants `Cmd`'s lifecycle
(DI, `--help`, `Printer` output, init hook).

### Dot-notation resolution

`api.user.SyncOrders` →

1. Replace `.` with `/`, ucfirst each segment → `Api/User/SyncOrders`
2. Replace `/` with `\` → `Api\User\SyncOrders`

The class must be autoloadable under one of the project's PSR-4 prefixes;
plugins registered via `Plugin::getPlugins()` are also searched by
`call sc list`.

### Argument forwarding

When you run `call sc app.console.SeedUsers --count=100 -v`, the inner
command receives:

| Bucket      | Value                                  |
|-------------|----------------------------------------|
| `arguments` | `['app.console.SeedUsers']` (positionals, stripped of `script`/`sc` prefix) |
| `options`   | `['count' => '100']`                   |
| `flags`     | `['v']`                                |

The class then runs through the standard `Cmd::script()` lifecycle —
`init()` → `isHelp()` (only for `Cmd`) → `handle()`.

---

## `call sc list` — discover scripts

Walks every PSR-4 root mapped under `Kernel::$pathRoot` (plus registered
plugin paths under `vendor/`) and lists every non-abstract class that
extends `Cmd` or `CmdCustom`.

```bash
call sc list
```

Sample output:

```
 | [============ Script ============]
 | [ Available Scripts ]
 |   App.Console.SeedUsers ............. [Cmd]
 |   App.Tasks.RebuildIndex ............ [CmdCustom]
 |   Acme.Plugin.Bill.Reconcile ........ [Cmd]
 | [ Available Scripts ]
```

The badge tells you which base class the script extends, which affects
whether `-h` / `--help` works (only `Cmd`) and whether `static help()`
is enforced.

---

## Examples

```bash
call sc list
call sc app.console.SeedUsers
call sc app.console.SeedUsers --count=100 --reset
call sc my.custom.Task -v
call sc acme.plugin.bill.Reconcile --plugin=bill
```

If the class is missing or doesn't extend `Cmd`/`CmdCustom`,
`script` prints a warning with the resolved FQCN — handy for typo
hunting:

```
 | [!] Class 'Foo' not found.
 | [i] Resolved: App\Foo
 | [i] Run 'call sc list' to see available scripts.
```

---

## When to use which base class

| Use case                                | Base class    | Why                                |
|-----------------------------------------|---------------|------------------------------------|
| Project-local script you'd run by hand  | `CmdCustom`   | Lighter — no `$title`, no `help()` |
| Reusable command worth listing in `help`| `Cmd`         | Discoverable, gets `-h` for free   |
| Plugin command shipped to consumers     | `Cmd`         | Same as above                      |

Note that **only `script`** finds custom classes by reflection — they
are not enumerated by `call help`. To make a script reachable as
`call <name>` directly, it would need to live as
`Flytachi\Winter\Console\Command\<Name>` (i.e. inside the kernel itself),
which is not how user code is normally distributed. Stick with
`call sc <dot.Class>` for project scripts.

---

## Source

- `console/Command/Script.php`
- Base classes: `console/Inc/Cmd.php`, `console/Inc/CmdCustom.php`
- Plugin discovery: `src/Plugin.php`

## See also

- [00-overview.md](00-overview.md) — `Cmd` vs `CmdCustom`
- [02-make.md](02-make.md) — `call make .Foo -n` to scaffold a `Cmd`
- [01-help.md](01-help.md) — listing **built-in** commands (does not list custom scripts)
