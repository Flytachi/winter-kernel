# `call complete` — shell completion endpoint (internal)

Internal command that the installed shell-completion script
(`_call` for zsh, `call` for bash) executes on every TAB to ask the
framework what to suggest. **End users do not run `complete` directly.**

To install the completion handlers themselves, use
[`call cfg completion -i`](03-cfg.md#cfg-completion--shell-tab-completion).

---

## How it fits together

```
user presses TAB
   ↓
shell loads ~/.zsh/completions/_call  (or ~/.bash_completion.d/call)
   ↓
that script runs:    call complete
   ↓
Complete::handle()   reads $COMP_LINE / $COMP_POINT,
                     prints filtered suggestions on stdout
   ↓
shell renders them as completion candidates
```

The completion script and the runtime endpoint are wired together by
`cfg completion -i`:

| Shell | Installed file              | Wiring                                        |
|-------|------------------------------|-----------------------------------------------|
| zsh   | `~/.zsh/completions/_call`   | `fpath=(...)` prepended to `~/.zshrc` before `compinit` |
| bash  | `~/.bash_completion.d/call`  | `source` loop appended to `~/.bashrc`         |

---

## How `complete` decides what to suggest

`Complete::handle()` reads `COMP_LINE` (everything typed) and
`COMP_POINT` (cursor offset), then tokenizes up to the cursor:

```
tokens[0]  = script name        (e.g. "call")
tokens[1]  = command            (e.g. "thread")
tokens[2]  = subcommand / class (e.g. "build", or a Dispatchable class)
tokens[3]  = action             (e.g. "status" after a daemon class)
current    = word being typed   (may be "" if a space precedes the cursor)
```

Suggestions are produced in this order:

1. **No command yet** → list of command names + their `$title`s,
   plus aliases (`sc`, `th`).
2. **Command typed (no subcommand)** → static suggestions from the
   `$map['<cmd>']` entry in `Complete.php`, plus dynamic entries:
   - `script` / `sc` → discovered `Cmd` / `CmdCustom` FQCNs (dot-notation).
   - `thread` / `th` → discovered `Dispatchable` FQCNs (dot-notation).
   - `help` → command-name list.
3. **Class / subcommand typed** → context-aware for `thread`:
   - after a non-daemon class → `-d`;
   - after a daemon class → `start` / `stop` / `status` / `-d`;
   - after `<daemon> status` → `-v`;
   - otherwise the `$map['<cmd> <sub>']` entry.
4. The candidate set is then **prefix-filtered** by the current word.

Suggestion strings can carry an inline description after a colon,
which the shell renders as a hint:

```
make:generate framework component templates
-c:Controller — API controller
build:scan controllers and write the route cache file
```

---

## What's in `$map`

`Complete::$map` is the static portion of the suggestion engine —
every supported `command [sub]` key and its list of completions.
It lives at the top of `console/Command/Complete.php` and looks like:

```php
'make' => [
    '-c:Controller — API controller',
    '-s:Service — business logic service',
    ...
],
'cfg docker' => [
    '--fpm:PHP-FPM + Nginx mode (default)',
    '--swoole:Swoole HTTP server mode',
],
'thread' => [
    'list:list all Dispatchable classes',
    'daemons:list daemons with live status',
],
```

**Adding suggestions for a new command**: add the appropriate `cmd` and
`cmd sub` entries to `$map` and any dynamic enrichment branches in
`suggest()`.

---

## Dynamic discovery

Three branches in `suggest()` enrich the static map at runtime:

| Branch                      | Adds                                              |
|-----------------------------|---------------------------------------------------|
| `script` / `sc` (no sub)    | All `Cmd` / `CmdCustom` classes (dot-notation)    |
| `thread` / `th` (no sub)    | All `Dispatchable` classes (dot-notation)         |
| `thread <daemon>`           | `start` / `stop` / `status` / `-d`                |
| `thread <daemon> status`    | `-v`                                              |
| `help` (no sub)             | All built-in command names                        |

Discovery runs via `ClassScanner` over `Kernel::$pathRoot` **and registered
plugins**, skipping the rest of `vendor/`.

---

## Why `help()` is empty

`Complete::help()` is intentionally a no-op — the command is never
invoked by humans, only by the shell. Listing it under `call help` is
suppressed inside `Complete::getCommandNames()`:

```php
if ($name === 'complete') {
    continue;
}
```

---

## Manual invocation (for debugging)

If completion doesn't seem to work after install, you can simulate the
shell call yourself:

```bash
COMP_LINE='call make .User -' COMP_POINT=20 call complete
COMP_LINE='call cfg '         COMP_POINT=9  call complete
COMP_LINE='call '             COMP_POINT=5  call complete
```

Each prints the candidate list to stdout, one per line.

---

## Source

- `console/Command/Complete.php`
- Aliases: `console/Core.php::$aliases`
- Installer: `console/Command/Cfg.php::completionArg()`
- Templates: `console/Template/Build/completion`,
  `completion_zsh`, `completion_bash`

## See also

- [03-cfg.md](03-cfg.md#cfg-completion--shell-tab-completion) — installing completion
- [01-help.md](01-help.md) — `call help` discovery
- [05-script.md](05-script.md) — `call sc list` (same discovery as `sc` completion)
- [09-thread.md](09-thread.md) — `call thread list` / `daemons` (same discovery as `thread` completion)
