# `call help` — list commands and show usage

Discovery entry point for the CLI. With no arguments it prints framework
metadata and every registered command; with an argument it dispatches to
that command's `static help()`.

---

## Synopsis

```
call help              # list all commands + environment info
call help <command>    # detailed help for one command
```

`-h` / `--help` on any command produces the same output as
`call help <command>` — both call the command's `static help()`.

---

## Description

`help` is the only command without subcommands. It does two things:

1. **No argument** — scans `console/Command/*.php`, reads each class's
   `public static string $title`, and prints a list of commands plus
   the runtime environment (project, kernel version, PHP, OS, SAPI,
   project root).
2. **One argument** — calls `Flytachi\Winter\Console\Command\<Ucwords>::help()`
   directly. The name is case-insensitive (`call help cfg` and
   `call help Cfg` both work because of `ucwords()`).

Custom commands (`Cmd` subclasses found by `call sc list`) are **not**
listed by `help` — `help` only enumerates the built-in classes in
`console/Command/`. To list user scripts, use `call sc list`.

---

## Arguments

| Position | Argument      | Required | Description                            |
|----------|---------------|----------|----------------------------------------|
| 1        | `<command>`   | no       | Built-in command name. Case-insensitive. |

No flags, no options.

---

## Examples

```bash
call help              # everything
call help make         # full help for `make`
call help cfg          # full help for `cfg`
call help run
```

---

## Sample output (no argument)

```
 | [============ Winter Framework ============]
 |       Project          acme/api (1.4.2)
 |       Kernel           1.0.0
 |       PHP              8.4.1
 |       OS               Darwin / Darwin
 |       SAPI             cli
 |       Project root     /srv/acme
 | - - - - - - - - - - - - - - - - - - - - - - -
 | [ Available Commands ]
 |   help ......................... [list commands and show usage information]
 |   make ......................... [generate framework component templates]
 |   ...
 | - - - - - - - - - - - - - - - - - - - - - - -
 | [i] Run 'call help <command>' for detailed usage.
 | [============ Winter Framework ============]
```

---

## Source

- `console/Command/Help.php`
- Base: `console/Inc/Cmd.php`

## See also

- [00-overview.md](00-overview.md) — anatomy of a command
- [05-script.md](05-script.md) — listing user-defined `Cmd` / `CmdCustom` scripts
- [11-complete.md](11-complete.md) — shell completion
