# Response

---

## ResponseEntity

Spring Boot-style response wrapper. Controller methods return a `ResponseEntity`; the
Router serializes it to the underlying `HttpResponse` — controllers never call
`$response->end()` themselves.

```php
use Flytachi\Winter\Kernel\Http\Response\ResponseEntity;

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
use Flytachi\Winter\Kernel\Http\Response\Sendable;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;

class CsvResponse implements Sendable
{
    public function __construct(private string $csv, private string $filename) {}

    public function send(HttpResponse $response, HttpRequest $request): void
    {
        $response->status(200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', "attachment; filename=\"{$this->filename}\"");
        $response->end($this->csv);
    }
}
```

`send()` receives the `HttpRequest` so a response can negotiate with it (HTTP
Range, conditional GET, content negotiation). Implementations that don't need it
simply ignore the argument.

Built-in `Sendable` implementations: `ResponseEntity`, `ResponseFile`, `ResponseStreamFile`, `ResponseView`.

---

## ResponseFile

File and download responses. All formats set `Content-Encoding: identity` and `Content-Length` to prevent compression from corrupting the content.

### Factory methods

```php
use Flytachi\Winter\Kernel\Http\Response\ResponseFile;

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

> `ResponseFile::file()` reads the whole file into memory. For large files prefer
> `ResponseStreamFile` (below), which streams from disk and supports HTTP Range.

---

## ResponseStreamFile

Streams a file from disk **without loading it fully into memory** — through the
`HttpResponse::sendfile()` primitive (zero-copy in the kernel on Swoole, a
memory-light `fpassthru` stream on FPM). Use it for large files (video, audio,
archives, database dumps) where `ResponseFile::file()` would waste worker memory.

```php
use Flytachi\Winter\Kernel\Http\Response\ResponseStreamFile;

return ResponseStreamFile::open('/var/media/video.mp4');                  // inline
return ResponseStreamFile::open('/var/export/report.pdf')->attachment();  // download
```

`open()` throws `RuntimeException` if the file does not exist; the MIME type is
auto-detected.

### Builder options

Same builder as `ResponseFile` (shared `FileResponseHeaders` trait), plus `acceptRanges()`:

```php
ResponseStreamFile::open('/var/media/clip.mp4')
    ->attachment()          // Content-Disposition: attachment
    ->inline()              // Content-Disposition: inline (default)
    ->maxAge(86400)         // Cache-Control: public, max-age=86400
    ->header('X-Source', 'cdn')
    ->acceptRanges(false);  // disable HTTP Range (see below)
```

### HTTP Range — media seeking & resumable downloads

`ResponseStreamFile` behaves as a correct file server. It advertises
`Accept-Ranges: bytes` and honors the request `Range` header:

| Request `Range` | Response |
|---|---|
| absent | `200 OK`, full file |
| `bytes=0-1023` | `206 Partial Content` + `Content-Range: bytes 0-1023/<size>` |
| `bytes=1024-` | `206`, from byte 1024 to EOF |
| `bytes=-500` | `206`, last 500 bytes |
| unsatisfiable (`bytes=99999999-`) | `416 Range Not Satisfiable` + `Content-Range: bytes */<size>` |
| multiple (`bytes=0-9,20-29`) | ignored → `200` full (multipart not supported) |

This is what makes `<video>`/`<audio>` seeking and download-manager resume work.

#### Safe resume — validators

Every response carries an `ETag` (derived from mtime + size, like nginx) and a
`Last-Modified` date. On resume the client sends `If-Range`; if the validator no
longer matches (the file changed), the server returns the full `200` instead of a
partial body — so the client never stitches together inconsistent bytes.
`If-Range` accepts both forms: an entity-tag or an HTTP-date.

#### Disabling Range

Range is enabled by default. Disable it per endpoint with `acceptRanges(false)`
when you need atomic full-file delivery — counting full downloads, one-shot
links, or on-the-fly transformation. The server then sends `Accept-Ranges: none`
and ignores any incoming `Range`:

```php
return ResponseStreamFile::open('/var/dl/installer.zip')->acceptRanges(false);
```

Range is a *mechanism*, not a forced policy — the application decides per endpoint.

### Conditional GET (cache revalidation)

Because the response carries `ETag` / `Last-Modified`, conditional requests are
honored: a matching `If-None-Match` or `If-Modified-Since` returns `304 Not
Modified` with no body. Browsers revalidate cached media/downloads without
re-fetching the bytes.

### `ResponseFile::file()` vs `ResponseStreamFile`

| | `ResponseFile::file()` | `ResponseStreamFile::open()` |
|---|---|---|
| File read into memory | yes (whole file) | no (streamed) |
| HTTP Range / `206` | no | yes |
| Conditional GET / `304` | no | yes |
| Best for | small files | large files, media, downloads |

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
use Flytachi\Winter\Kernel\Http\Response\ResponseView;

// Render a single template
return ResponseView::view('user/profile', ['user' => $user]);

// Render a resource wrapped inside a layout template
return ResponseView::render('layouts/main', 'user/profile', ['user' => $user]);
```

All `$data` keys are `extract()`-ed into the template scope as variables.
`ResponseView` sends `Content-Type: text/html; charset=utf-8`.

### Template helper functions

Inside any `.php` template or resource the following global helpers are available. They
read the current render through `RenderContext`, which `ResponseView` keeps in
`RequestLocal` for the duration of one render — per coroutine under Swoole, per process
under FPM.

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

---

## HEAD requests

Body suppression for `HEAD` is handled **centrally by the `HttpResponse` adapter**,
not by individual responses. When the request method is `HEAD`, every response —
`ResponseEntity`, `ResponseView`, `ResponseFile`, `ResponseStreamFile`, error
responses, static files, and custom `Sendable`s — emits the **same status and
headers as the equivalent `GET`** (including `Content-Length`) but sends no body.
For `ResponseStreamFile` this also applies to `206` range responses: the adapter
omits the bytes while keeping the range headers.

> The router does **not** auto-map `HEAD` to `GET` handlers. A `HEAD` request to a
> route that registers only `GET` returns `405 Method Not Allowed` — register an
> explicit `HEAD` mapping if a handler must serve it.

---

## HttpResponse contract

Every response object writes through the unified `HttpResponse` abstraction
(`SwooleResponse` / `FpmResponse`), so the same code runs under Swoole and FPM:

| Method | Purpose |
|---|---|
| `status(int $code)` | set the HTTP status code |
| `header(string $name, string $value)` | add or replace a response header |
| `end(string $body = '')` | flush the body and finish the response |
| `sendfile(string $path, int $offset = 0, int $length = 0)` | stream a file (or byte window) from disk and finish; zero-copy on Swoole |

`sendfile()` finishes the response itself — set headers beforehand, and do not
call `end()` after it.
