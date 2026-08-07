# `#[RequestFile]`

Binds a multipart uploaded file to a controller method parameter. Files reach the
controller pre-validated and ready to use — transfer errors, size limits, and MIME
type restrictions are all enforced by `ParameterResolver` before the method is called.

```php
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestFile;
```

---

## Syntax

```php
#[RequestFile]                                            // all uploaded files as map
#[RequestFile('field')]                                   // single file by form field name
#[RequestFile('field', multiple: true)]                   // multiple files in one field
#[RequestFile('field', maxSize: '5MB')]                   // with size limit
#[RequestFile('field', accept: ['image/jpeg', '.png'])]   // with MIME / extension filter
#[RequestFile('field', multiple: true, maxSize: '2MB', accept: ['image/*'])]  // combined
```

---

## Parameters

| Parameter  | Type      | Default | Description |
|------------|-----------|---------|-------------|
| `name`     | `?string` | `null`  | Form field name. Omit to receive all files. |
| `multiple` | `bool`    | `false` | Expect a list of files from one field (`images[]`). |
| `maxSize`  | `?string` | `null`  | Maximum file size: `'500KB'`, `'5MB'`, `'1.5MB'`, `'1GB'`, or plain bytes `'2048'`. |
| `accept`   | `array`   | `[]`    | Allowed file types — MIME, wildcard, or extension (see [Accept Formats](#accept-formats)). |

---

## How Values Reach the Controller

Uploaded files in HTTP are sent with `Content-Type: multipart/form-data`. PHP parses
them from the raw request body and exposes them via `$_FILES` (FPM) or
`$request->files` (Swoole). The framework normalizes both into a consistent structure
before `ParameterResolver` sees them.

```
POST /upload  Content-Type: multipart/form-data
        │
        ▼
PHP / Swoole parses multipart body
        │  FpmRequest::getUploadedFiles()   → normalizes $_FILES
        │  SwooleRequest::getUploadedFiles() → $request->files (already normalized)
        │
        │  single field:   ['avatar' => ['name'=>'photo.jpg', 'tmp_name'=>'/tmp/...', 'size'=>12345, 'error'=>0]]
        │  multiple field: ['images' => [['name'=>'a.jpg',...], ['name'=>'b.jpg',...]]]
        ▼
ParameterResolver — for #[RequestFile]:
        │  1. fetch field from uploaded files map
        │  2. check presence (required unless nullable / default)
        │  3. validate error code  → 400 on transfer error
        │  4. validate maxSize     → 400 if size > limit
        │  5. validate accept      → finfo detects MIME from file bytes → 400 if not allowed
        │  6. return array (file info) or string (file contents)
        ▼
Controller::method(file: [...])
```

MIME type detection in step 5 uses PHP's `finfo` extension which reads the file's
magic bytes — it does **not** trust the `Content-Type` the browser sends, which can
be spoofed.

---

## Rules

1. Request must use `multipart/form-data` encoding.
2. Without `name` — returns the full uploaded files map; `maxSize`/`accept` applied to every file.
3. `multiple: false` (default) — returns a single file info array; if the client uploads multiple files to the same field, the first one is used.
4. `multiple: true` — returns a list of file info arrays; a single uploaded file is normalized to a one-element list.
5. `string` type — returns the file contents loaded into memory (single file only).
6. `maxSize` uses binary units: `1KB = 1024 bytes`, `1MB = 1048576 bytes`. A file exactly at the limit is allowed (`size > limit`, not `>=`).
7. `accept` validation uses `finfo` — MIME is detected from file magic bytes, not the browser-supplied `Content-Type`.
8. Missing file throws `400` unless the parameter is nullable or has a default value.

---

## Single File

```php
// File info array
public function upload(
    #[RequestFile('avatar')] array $file,
): ResponseEntity
// $file = ['name' => 'photo.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/...', 'size' => 12345, 'error' => 0]

// File contents loaded into memory
public function upload(
    #[RequestFile('avatar')] string $bytes,
): ResponseEntity

// Optional
public function update(
    #[RequestFile('thumbnail')] ?array $thumbnail = null,
): ResponseEntity
```

---

## Multiple Files

Use `multiple: true` when the form field sends several files via bracket notation
(`<input type="file" name="images[]" multiple>`):

```php
public function gallery(
    #[RequestFile('images', multiple: true)] array $images,
): ResponseEntity
// $images = [
//   ['name' => 'a.jpg', 'tmp_name' => '/tmp/...', 'size' => 12345, 'error' => 0],
//   ['name' => 'b.jpg', 'tmp_name' => '/tmp/...', 'size' => 67890, 'error' => 0],
// ]
```

A single file sent to that field is automatically normalized to a one-element list —
the controller always receives an array of arrays.

---

## All Files

Omit `name` to receive the complete uploaded files map:

```php
public function upload(
    #[RequestFile] array $files,
): ResponseEntity
// $files = ['avatar' => [...], 'images' => [[...], [...]]]
```

---

## Size Limit

```php
#[RequestFile('avatar', maxSize: '5MB')]   array $file   // ≤ 5 242 880 bytes
#[RequestFile('doc',    maxSize: '500KB')] array $file   // ≤ 512 000 bytes
#[RequestFile('raw',    maxSize: '2048')]  array $file   // ≤ 2048 bytes
```

Supported units (binary):

| Notation | Bytes |
|----------|-------|
| `500KB`  | 500 × 1 024 |
| `5MB`    | 5 × 1 048 576 |
| `1.5MB`  | 1.5 × 1 048 576 |
| `1GB`    | 1 × 1 073 741 824 |
| `2048`   | 2 048 (no unit = bytes) |

When combined with `multiple: true`, the limit is applied individually to each file.

---

## Accept Formats

MIME type is detected from the file's actual content (magic bytes via `finfo`), not
from the filename or the browser-supplied `Content-Type` header.

| Format | Example | Matches |
|--------|---------|---------|
| Exact MIME | `'image/jpeg'` | Only JPEG files |
| Wildcard MIME | `'image/*'` | Any image: JPEG, PNG, GIF, WebP, … |
| Extension | `'.jpg'` | Files whose original name ends in `.jpg` (client-provided, not verified by magic bytes) |

```php
// Exact MIME
#[RequestFile('avatar', accept: ['image/jpeg', 'image/png'])] array $file

// Wildcard — any image type
#[RequestFile('photo', accept: ['image/*'])] array $file

// Extension check (uses original filename)
#[RequestFile('doc', accept: ['.pdf', '.docx'])] array $file

// Mixed
#[RequestFile('upload', accept: ['image/*', 'application/pdf'])] array $file
```

> **Extension vs MIME:** Extension matching checks `$_FILES['name']` (the original
> filename the client sent). It is useful for formats that `finfo` may not distinguish
> (e.g. `.docx` vs `.xlsx` are both `application/zip` internally). For security-sensitive
> uploads, prefer MIME or wildcard — a client can rename any file to `.jpg`.

---

## Combined Example

```php
public function uploadImages(
    #[RequestFile('cover',  maxSize: '2MB',  accept: ['image/jpeg', 'image/png'])] array  $cover,
    #[RequestFile('photos', multiple: true, maxSize: '5MB', accept: ['image/*'])]  array  $photos,
    #[RequestFile('doc',    maxSize: '10MB', accept: ['application/pdf', '.docx'])] ?array $doc = null,
): ResponseEntity
```

---

## Error Messages

| Situation             | HTTP | Message |
|-----------------------|------|---------|
| Missing required      | 400  | `Uploaded file 'avatar' is missing` |
| Transfer error        | 400  | `Uploaded file 'avatar' transfer error (code 1)` |
| Size exceeded         | 400  | `Uploaded file 'avatar' exceeds maximum size of 5MB` |
| Type not allowed      | 400  | `Uploaded file 'avatar' type 'image/gif' is not allowed (accepted: image/jpeg, image/png)` |
| File not readable     | 400  | `Uploaded file 'avatar' is not readable` |

---

## Related

| Topic | File |
|-------|------|
| Overview — resolution priority | [00-overview.md](00-overview.md) |
| Request body (non-file) | [03-request-body.md](03-request-body.md) |
| Full query string as DTO | [06-request-query.md](06-request-query.md) |
