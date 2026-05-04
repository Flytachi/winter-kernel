# Response

---

## ResponseEntity

Spring Boot-style response wrapper. Controller methods return a `ResponseEntity`; the
Router serializes it to the underlying `HttpResponse` — controllers never call
`$response->end()` themselves.

```php
use Flytachi\Winter\K2\Http\Response\ResponseEntity;

// Static factory shortcuts
return ResponseEntity::ok($data);               // 200
return ResponseEntity::created($data);          // 201
return ResponseEntity::accepted($data);         // 202
return ResponseEntity::noContent();             // 204
return ResponseEntity::badRequest('message');   // 400
return ResponseEntity::unauthorized();          // 401
return ResponseEntity::forbidden();             // 403
return ResponseEntity::notFound('message');     // 404
return ResponseEntity::conflict($data);         // 409
return ResponseEntity::unprocessable($data);    // 422
return ResponseEntity::internalError();         // 500

// Arbitrary status
return ResponseEntity::status(HttpCode::ACCEPTED)->body($data);

// Custom headers (chainable)
return ResponseEntity::ok($data)->header('X-Request-Id', $requestId);
```

### Content negotiation

When the body is an **array or object**, `ResponseEntity::send()` selects the serialization format from the client's `Accept` header:

| Accept header         | Serialized as |
|-----------------------|---------------|
| `application/json`    | JSON          |
| `application/xml`     | XML           |
| `text/html`           | JSON (API default — HTML views use `ResponseView`) |
| `*/*` or absent       | JSON          |

**Scalar values** (`string`, `int`, `float`, `bool`) are always sent as `text/plain; charset=utf-8`.

Objects with a `toArray()` method are converted before content negotiation.

---

## Non-ResponseEntity returns

If a controller returns any non-null value that is **not** a `Sendable`, the Router wraps it automatically:

```php
// These are equivalent:
return ['key' => 'value'];
return ResponseEntity::ok(['key' => 'value']);
```

Returning `null` (or `void`) closes the connection with no body and no explicit status.
Prefer `ResponseEntity::noContent()` for an explicit 204.

---

## Sendable interface

`Sendable` is the common contract for all response objects. Implement it for fully custom responses:

```php
use Flytachi\Winter\K2\Http\Response\Sendable;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;

class CsvResponse implements Sendable
{
    public function __construct(private string $csv, private string $filename) {}

    public function send(HttpResponse $response): void
    {
        $response->status(200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', "attachment; filename=\"{$this->filename}\"");
        $response->end($this->csv);
    }
}
```

Built-in `Sendable` implementations: `ResponseEntity`, `ResponseFile`, `ResponseView`.

---

## ResponseFile

File and download responses. All formats set `Content-Encoding: identity` and `Content-Length` to prevent compression from corrupting the content.

### Factory methods

```php
use Flytachi\Winter\K2\Http\Response\ResponseFile;

// Raw bytes — any MIME type
ResponseFile::binary($data, 'report.bin');
ResponseFile::binary($data, 'report.bin', 'application/octet-stream');

// Plain text
ResponseFile::txt($content, 'readme.txt');

// CSV — accepts array of rows (each row is an array)
ResponseFile::csv($rows, 'export.csv');

// JSON — array or pre-encoded string
ResponseFile::json($array, 'data.json');
ResponseFile::json($jsonString, 'data.json');

// XML — SimpleXMLElement, stdClass, array, or scalar
ResponseFile::xml($data, 'feed.xml');

// From an existing file path — MIME auto-detected
ResponseFile::file('/var/export/report.pdf');
ResponseFile::file('/var/export/report.pdf', isAttachment: true);
```

### Builder options

```php
ResponseFile::csv($rows, 'export.csv')
    ->attachment()         // force Content-Disposition: attachment (download dialog)
    ->inline()             // Content-Disposition: inline (render in browser)
    ->maxAge(3600)         // Cache-Control: public, max-age=3600
    ->header('X-Source', 'generated');
```

Default `isAttachment`:
- `binary`, `csv` → `true` (download dialog)
- `txt`, `json`, `xml`, `file` → `false` (inline)

---

## ResponseView

PHP template response. Renders `.php` files as HTML.

### Configuration

Set the base path once in bootstrap (defaults to `Kernel::$pathResource` if not set):

```php
ResponseView::setBasePath(__DIR__ . '/resources/views');
```

### Factory methods

```php
use Flytachi\Winter\K2\Http\Response\ResponseView;

// Render a single template
return ResponseView::view('user/profile', ['user' => $user]);

// Render a resource wrapped inside a layout template
return ResponseView::render('layouts/main', 'user/profile', ['user' => $user]);
```

All `$data` keys are `extract()`-ed into the template scope as variables.
`ResponseView` sends `Content-Type: text/html; charset=utf-8`.

In DEBUG mode a debug overlay is appended to the rendered HTML by `RenderContext`.

### Template helper functions

Inside any `.php` template or resource the following global helpers are available:

| Function | Description |
|---|---|
| `wrContent()` | Outputs the rendered resource HTML inside a layout template |
| `wrImport('partial/name')` | Includes another view file in the current scope |
| `wrData(?string $key)` | Returns a value from `$data` by key, or the full array if `null` |
| `wrIsActiveLink(string\|array $link, string $success, string $none)` | Returns CSS class based on current request URI |

Example layout (`layouts/main.php`):

```php
<!doctype html>
<html>
<head><title><?= wrData('title') ?></title></head>
<body>
    <?php wrContent(); ?>
</body>
</html>
```

### Custom status / headers

```php
return ResponseView::view('errors/404', [], HttpCode::NOT_FOUND)
    ->header('Cache-Control', 'no-store');
```
