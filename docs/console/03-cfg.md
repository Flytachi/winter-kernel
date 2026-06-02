# `call cfg` — project configuration, environment, keys, Docker, completion

Bootstrap and maintenance for project-level configuration:
`.env` file, `WINTER_KEY`, Docker scaffolding, and shell tab-completion.

---

## Synopsis

```
call cfg <command> [-flags] [--options]
```

| Command       | Purpose                                                          |
|---------------|------------------------------------------------------------------|
| `init`        | Initialize the project (`composer.json` patch + `.env` + key)    |
| `key`         | Manage `WINTER_KEY`                                              |
| `env`         | Manage the `.env` file                                           |
| `docker`      | Scaffold Docker files (fpm or swoole)                            |
| `completion`  | Install or print the shell tab-completion script                 |

---

## `cfg init`

One-shot project bootstrapper. Performs:

1. Patches `composer.json` (renames `name` to `project/<dir>`, blanks
   `authors`, removes `keywords` and `post-create-project-cmd`).
2. Copies `console/Template/Build/env` → `.env` (skipped if it exists).
3. Generates a fresh 64-char `WINTER_KEY` and writes it to `.env`.
4. Drops a `.phpstorm.meta.php` stub into `vendor/.phpstorm.meta/`.

```bash
call cfg init
```

---

## `cfg key` — `WINTER_KEY` management

`WINTER_KEY` is a 32-byte (64 hex) project secret used for token signing
(cursor pagination, CSRF, etc.).

| Flag | Action                                                       |
|------|--------------------------------------------------------------|
| `-g` | Generate (or regenerate) and save to `.env`                  |
| `-s` | Show current value                                           |

```bash
call cfg key -g      # rotate the key
call cfg key -s      # print current
```

`-g` fails with a warning if `.env` does not exist — run `cfg env -i` first.

---

## `cfg env` — environment file

| Flag         | Action                                                          |
|--------------|-----------------------------------------------------------------|
| `-i`         | Copy `.env` from `console/Template/Build/env` (skipped if exists)|
| `-s`         | Print loaded `$_ENV` (post-dotenv merge)                        |
| `-s --file`  | Print raw `.env` contents                                       |

```bash
call cfg env -i
call cfg env -s
call cfg env -s --file
```

`-i` also installs the PhpStorm meta stub for better IDE support.

---

## `cfg docker` — Docker scaffold

Copies `console/Template/Docker/shared/*` + `console/Template/Docker/<mode>/*`
to the project root. Default mode is `fpm`.

| Option     | Mode                              |
|------------|-----------------------------------|
| `--fpm`    | PHP-FPM + Nginx (default)         |
| `--swoole` | Swoole HTTP server                |

```bash
call cfg docker            # fpm (default)
call cfg docker --fpm      # explicit
call cfg docker --swoole   # swoole mode
```

Writes `docker/`, `.dockerignore`, `docker-compose.yml`, `Dockerfile`.

---

## `cfg completion` — shell tab-completion

Installs (or prints) the tab-completion script for `call`. Detects the
shell from `$SHELL`:

| Shell | Installed location              | Wiring                                 |
|-------|----------------------------------|----------------------------------------|
| zsh   | `~/.zsh/completions/_call`       | `fpath=(...)` line prepended to `~/.zshrc` (before `compinit`) |
| bash  | `~/.bash_completion.d/call`      | `source` loop appended to `~/.bashrc`  |

| Flag        | Action                                                       |
|-------------|--------------------------------------------------------------|
| _(no flag)_ | Print the completion script to stdout                        |
| `-i`        | Install globally for the current user                        |
| `-if`       | Force-overwrite an existing installed file                   |

```bash
call cfg completion              # print to stdout
call cfg completion -i           # install
call cfg completion -if          # force update
exec zsh                         # reload shell after install
```

This is the user-facing installer; the runtime suggestion engine lives
in `call complete` (internal, see [11-complete.md](11-complete.md)).

---

## Examples

```bash
call cfg init
call cfg key -g
call cfg key -s
call cfg env -i
call cfg env -s --file
call cfg docker --swoole
call cfg completion -i
```

---

## Source

- `console/Command/Cfg.php`
- Templates: `console/Template/Build/`, `console/Template/Docker/`

## See also

- [11-complete.md](11-complete.md) — internals of the completion endpoint
- [`../configuration/01-kernel.md`](../configuration/01-kernel.md) — kernel boot
- [`../configuration/02-logging.md`](../configuration/02-logging.md) — `LOGGER_*` env vars
