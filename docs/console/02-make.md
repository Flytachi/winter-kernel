# `call make` — generate framework component templates

Scaffolds skeleton files for the framework's component types from
templates in `console/Template/Make/`. Each flag adds one component
type to the generated batch; you can combine flags to scaffold several
files at once for the same name.

---

## Synopsis

```
call make <dot.notation.Name> -[flags] [--mvc]
```

The first argument is a **dot-notation path** ending in the class name.
Flags select which component types to generate.

---

## Description

`make` resolves the dot-notation path to a target directory + namespace,
fills a template per selected flag, and writes the resulting `.php` file
under the matched PSR-4 root. Existing files are never overwritten —
they are reported as `[EXISTS]`.

Each component type has its own template, suffix convention, and a
"smart path" list. If the input path is empty (`call make .Name -c`),
`make` walks the known sub-folders in order and drops the file into the
first one that exists (e.g. `Controllers/`, then `Controller/`,
then falls back to the PSR-4 root). The `--mvc` option short-circuits
this by prepending a category folder (`Controllers/`, `Services/`, …).

---

## Path resolution

| Input              | Behavior                                                     |
|--------------------|--------------------------------------------------------------|
| `.Name`            | Auto-detect first PSR-4 dir under app root; smart-path lookup |
| `Name`             | Same as `.Name`                                              |
| `api.user.Name`    | Relative sub-path: `<psr4-root>/Api/User/Name`               |
| `acme.app.api.user.Name` | Longest matching PSR-4 prefix → mapped directory       |

The longest PSR-4 prefix wins. `vendor/` paths are excluded.

---

## Flags

### HTTP
| Flag | Component  | Suffix       |
|------|------------|--------------|
| `-c` | Controller | `Controller` |
| `-m` | Middleware | `Middleware` |

### Data
| Flag | Component | Suffix |
|------|-----------|--------|
| `-e` | Entity    | _none_ |
| `-d` | Dto       | `Dto`  |
| `-p` | Response  | _none_ |

### Business
| Flag | Component  | Suffix       |
|------|------------|--------------|
| `-s` | Service    | `Service`    |
| `-r` | Repository | `Repository` |
| `-t` | Store      | `Store`      |

### Async / Process
| Flag | Component  | Suffix       |
|------|------------|--------------|
| `-J` | Job        | `Job`        |
| `-P` | Process    | `Process`    |
| `-N` | Daemon     | `Daemon`     |
| `-W` | WebSocket  | `WebSocket`  |

### Config / Console
| Flag | Component   | Suffix         |
|------|-------------|----------------|
| `-D` | DbConfig    | `DbConfig`     |
| `-R` | RedisConfig | `RedisConfig`  |
| `-n` | Cmd (custom command) | _none_ |

---

## Options

| Option   | Description                                                     |
|----------|-----------------------------------------------------------------|
| `--mvc`  | Wrap the output path in MVC category folders (`Controllers/`, `Services/`, `Repositories/`, `Entities/`, `Dto/`, `Requests/`, `Jobs/`, `Processes/`, `Sockets/`, `Commands/`, `Utils/`). |

---

## Examples

```bash
call make .User -csre              # Controller + Service + Repository + Entity
call make .Order -csre --mvc       # same, but under Controllers/ Services/ ...
call make api.user.Profile -c      # appRoot/Api/User/ProfileController.php
call make acme.app.http.User -c    # full PSR-4 path
call make .S1Job -J                # Job under Jobs/ (smart-path lookup)
call make .MySync -n               # custom Cmd
```

When several names are passed, `make` reports per-name progress:

```bash
call make .User .Order .Item -cs
```

---

## Sample output

```
 | [============ Make ============]
 | [1/3] .User
 |   UserController (rest) ......... [CREATED]
 | [i] file:///srv/acme/src/Controllers/UserController.php
 |   UserService (service) ......... [CREATED]
 | - - - - - - - - - - - - - - - - - - - - - - -
 | [2/3] .Order
 |   OrderController (rest) ........ [EXISTS]
 |   OrderService (service) ........ [CREATED]
 | ...
 | [============ Make ============]
```

---

## Templates

Templates live in `console/Template/Make/` and use three placeholders:

| Placeholder       | Replaced with                                  |
|-------------------|------------------------------------------------|
| `__namespace__`   | Resolved namespace                             |
| `__className__`   | Final class name (with suffix applied)         |
| `__shortName__`   | Short identifier (Controllers only — without suffix, `lcfirst`) |
| `__tableName__`   | Repository templates — derived plural table name |

Add or override templates by dropping new files into
`console/Template/Make/`. The mapping flag→template file is in
`console/Command/Make.php::createXxx()`.

---

## Source

- `console/Command/Make.php`
- Templates: `console/Template/Make/*Template`

## See also

- [00-overview.md](00-overview.md) — command anatomy
- [05-script.md](05-script.md) — running custom `Cmd` made with `-n`
