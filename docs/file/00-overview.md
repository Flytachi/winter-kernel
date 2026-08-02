# File — CSV / JSON / XML read & write

Three static helper classes for moving plain arrays in and out of the most
common flat-file formats. No instances, no state — call the static methods
directly. On any I/O or parse problem they throw `FileException`.

Namespace: `Flytachi\Winter\Kernel\File`.

| Class  | Reads               | Writes              | Extra |
|--------|---------------------|---------------------|-------|
| `CSV`  | header → assoc rows | rows + header line  | configurable delimiter/enclosure/escape |
| `JSON` | assoc array         | pretty-printed JSON | — |
| `XML`  | element tree → array| array → XML file     | string ↔ array helpers, `ext-simplexml` |

`FileException extends \RuntimeException` and reports at log level
`CRITICAL`, so unhandled file errors stand out in the logs.

---

## CSV

```php
public static function read(
    string $path,
    string $delimiter = ',',
    string $enclosure = '"',
    string $escape = '\\',
    int $rowLength = 1000
): array

public static function write(string $path, array $data, ?array $head = null): void
```

`read()` treats the **first row as the header** and returns a list of
associative rows keyed by that header:

```php
use Flytachi\Winter\Kernel\File\CSV;

// users.csv:
//   id,name
//   1,Ada
//   2,Linus
$rows = CSV::read('users.csv');
// [
//   ['id' => '1', 'name' => 'Ada'],
//   ['id' => '2', 'name' => 'Linus'],
// ]
```

`write()` derives the header from the keys of the first element unless you
pass `$head` explicitly:

```php
CSV::write('out.csv', [
    ['id' => 1, 'name' => 'Ada'],
    ['id' => 2, 'name' => 'Linus'],
]);
// id,name
// 1,Ada
// 2,Linus

// Explicit header / column order
CSV::write('out.csv', $data, ['name', 'id']);
```

Notes:

- All values from `read()` are **strings** — CSV carries no types.
- A missing or unreadable `$path` in `read()` throws `FileException`.
- `read()` returns `[]` for a file that has only a header row (or is empty).
- Use a larger `$rowLength` for very wide rows; `0` lets PHP auto-size.

---

## JSON

```php
public static function read(string $path): array   // associative
public static function write(string $path, array $data): void
```

```php
use Flytachi\Winter\Kernel\File\JSON;

$config = JSON::read('config.json');          // decoded as an associative array
JSON::write('config.json', $config);          // written with JSON_PRETTY_PRINT
```

`read()` decodes objects to **associative arrays** (not `stdClass`) and
throws `FileException` if the file is missing, unreadable, or contains
invalid JSON. `write()` throws if the file cannot be written.

---

## XML

```php
public static function read(string $filePath): array
public static function write(string $filePath, array $content, string $rootElement = 'root'): void
public static function stringToArray(string $xmlString): array
public static function arrayToXml(array $data, string $rootElement = 'root', array $attrs = []): string
public static function isAvailable(): bool
```

```php
use Flytachi\Winter\Kernel\File\XML;

$tree = XML::read('feed.xml');                 // element tree → nested array
XML::write('feed.xml', $tree, 'feed');         // array → <feed>…</feed>

$arr = XML::stringToArray('<a><b>1</b></a>');  // parse an in-memory string
$xml = XML::arrayToXml(['b' => 1], 'a');       // serialize to an XML string
```

Conversion rules and limits:

| Behavior | Detail |
|----------|--------|
| Engine | Built on `ext-simplexml` via a SimpleXML ↔ JSON round-trip. |
| Numeric keys | Array keys that are numeric become `<item.N>` elements. |
| Values | Scalar values are HTML-escaped on write. |
| Lossiness | Attributes and mixed content are flattened — this maps **data**, not arbitrary document structure. |
| Availability | `isAvailable()` returns `false` when `ext-simplexml` is missing; every read/write throws `FileException` in that case. |

Guard environments where the extension may be absent:

```php
if (XML::isAvailable()) {
    $data = XML::read('feed.xml');
}
```

---

## Errors

Every failure path raises `Flytachi\Winter\Kernel\File\FileException`:

```php
use Flytachi\Winter\Kernel\File\CSV;
use Flytachi\Winter\Kernel\File\FileException;

try {
    $rows = CSV::read('missing.csv');
} catch (FileException $e) {
    // missing / unreadable file, parse error, or missing ext-simplexml
}
```

Because `FileException` reports at `CRITICAL`, an uncaught one is logged
prominently — catch it where a missing or malformed file is an expected,
recoverable condition.

---

## Source

- `src/File/CSV.php` — `read()`, `write()`
- `src/File/JSON.php` — `read()`, `write()`
- `src/File/XML.php` — `read()`, `write()`, `stringToArray()`, `arrayToXml()`, `isAvailable()`
- `src/File/FileException.php` — `CRITICAL` log level

## See also

- [`../architecture/06-exception.md`](../architecture/06-exception.md) — how
  `FileException` flows through the kernel's exception handling
- [`../console/08-storage.md`](../console/08-storage.md) — the file store /
  storage console commands
