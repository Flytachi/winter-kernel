# `call storage` — manage the `storage/` tree

Creates and cleans the three runtime folders the kernel expects:

- `storage/`         — root for generated, mutable state
- `storage/cache/`   — kernel and application caches
- `storage/logs/`    — log files

Each folder gets a `.gitignore` from `console/Template/Storage/` on
creation, so the directory is committed but its contents are not.

---

## Synopsis

```
call storage <command> [-flags]
```

| Command | Purpose                                                 |
|---------|---------------------------------------------------------|
| `init`  | Create the folder(s) and drop the `.gitignore` template |
| `clean` | Delete the contents (keeps the folder + `.gitignore`)   |

---

## Target flags

Both `init` and `clean` accept the same target flags. **Omit them all to
operate on every target.**

| Flag | Target                |
|------|-----------------------|
| `-s` | `storage/`            |
| `-c` | `storage/cache/`      |
| `-l` | `storage/logs/`       |

---

## `call storage init`

For each selected target:

- If the directory is missing → create it (mode `0777`), copy the
  matching `.gitignore`, badge `[CREATED]`.
- If it exists → badge `[EXISTS]`.

```bash
call storage init           # all three (default)
call storage init -s        # only storage/
call storage init -s -c     # storage/ + storage/cache/
call storage init -c -l     # cache + logs
```

The `.gitignore` templates live in `console/Template/Storage/`:
- `gitignoreStorage` → `storage/.gitignore`
- `gitignoreStorageCache` → `storage/cache/.gitignore`
- `gitignoreStorageLogs` → `storage/logs/.gitignore`

---

## `call storage clean`

For each selected target, walks the directory via `flushDirectory()` and
deletes every file **except** `.gitignore`. Sub-directories at the
storage root are excluded so `cache/` and `logs/` survive
`call storage clean -s`.

```bash
call storage clean          # everything under all three
call storage clean -c       # wipe storage/cache only
call storage clean -l       # wipe storage/logs only
call storage clean -s       # wipe stray files at storage/ root only
```

Output is per-file (or per-directory): `[DELETED]` or `[FAILED]`.

---

## Examples

```bash
call storage init
call storage init -s -c
call storage clean -l         # rotate logs without touching cache
call storage clean -c         # cold-start cache
call storage clean            # nuke everything (keeps gitignores)
```

---

## Sample output

```
 | [============ Storage ============]
 |   storage ............... [CREATED]
 |   storage/cache ......... [CREATED]
 |   storage/logs .......... [EXISTS]
 | [============ Storage ============]
```

---

## Notes

- Both commands operate purely on `Kernel::$pathStorage`,
  `Kernel::$pathStorageCache`, `Kernel::$pathStorageLog` — change those
  in `Kernel` to change targets globally.
- `storage init` is typically run once per project after `cfg init`;
  CI/CD images should run it as a build step rather than at runtime so
  the resulting container has the folders pre-created with the right
  ownership.

---

## Source

- `console/Command/Storage.php`
- Templates: `console/Template/Storage/`
- Helper: `function/dependencies.php` (`flushDirectory()`)

## See also

- [03-cfg.md](03-cfg.md) — initial project bootstrap (`cfg init`)
- [`../configuration/02-logging.md`](../configuration/02-logging.md) — what writes to `storage/logs/`
