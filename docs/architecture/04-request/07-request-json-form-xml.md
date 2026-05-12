# `#[RequestJson]` / `#[RequestForm]` / `#[RequestXml]`

Force a specific body format regardless of the `Content-Type` header.
Use when you need strict format enforcement instead of auto-detection.

```php
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestXml;
```

---

## Rules

1. Format is **not** detected from `Content-Type` — forced unconditionally.
2. Supported parameter types: `array`, `stdClass` / `object`, any class with a constructor.
3. Plain DTO classes are hydrated via reflection — no base class required.
4. Nested class-typed fields are hydrated recursively (same as `#[RequestBody]`).
5. Variadic `...$items` is supported **only by `#[RequestJson]` and `#[RequestXml]`**, not by `#[RequestForm]`. `#[RequestJson]` expects a JSON array; `#[RequestXml]` wraps a single document into a one-element collection.
6. Use `#[RequestBody]` instead if you want `Content-Type` auto-detection.

---

## Supported Types

### `array`
```php
public function create(#[RequestJson] array $data): ResponseEntity
// Body forced as JSON → json_decode($raw, true) or []
```

### `stdClass` / `object`
```php
public function create(#[RequestJson] \stdClass $data): ResponseEntity
// Body forced as JSON → json_decode($raw) or new stdClass()
```

### Plain DTO — any class with constructor
```php
class FilterDto
{
    public function __construct(
        public readonly int     $minPrice,
        public readonly int     $maxPrice,
        public readonly ?string $category = null,
    ) {}
}

public function search(#[RequestJson] FilterDto $filter): ResponseEntity
// Body: {"minPrice":10,"maxPrice":500}
// → FilterDto{minPrice:10, maxPrice:500, category:null}
```

---

## `#[RequestJson]`

Parses body as JSON. Body is always decoded regardless of `Content-Type`.

```php
public function create(#[RequestJson] CreateOrderDto $dto): ResponseEntity
// {"title":"Widget","amount":5} → CreateOrderDto{...}

public function list(#[RequestJson] array $filters): ResponseEntity
// {"status":"active","page":1} → ['status' => 'active', 'page' => 1]

// Variadic — JSON array expected; JSON object → 400
public function bulk(#[Valid] #[RequestJson] CreateOrderDto ...$items): ResponseEntity
// [{"title":"A",...},{"title":"B",...}] → [CreateOrderDto, CreateOrderDto]
```

---

## `#[RequestForm]`

Parses form data: `application/x-www-form-urlencoded` or `multipart/form-data`.
Internally uses `$request->getParsedBody() + $request->getQueryParams()` —
parsed body (POST fields) takes precedence over query string.

Works in **both FPM and Swoole** via the `HttpRequest` abstraction.

```php
public function submit(#[RequestForm] ContactFormDto $form): ResponseEntity
// POST: name=Alice&email=alice@example.com

class ContactFormDto
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}
```

---

## `#[RequestXml]`

Parses body as XML using `simplexml_load_string`. XML attributes and namespaces
are flattened to array/object keys. Invalid XML → empty array / empty `stdClass`.

```php
public function ingest(#[RequestXml] EventDto $event): ResponseEntity
// Body: <root><type>order</type><id>42</id></root>

class EventDto
{
    public function __construct(
        public readonly string $type,
        public readonly int    $id,
    ) {}
}

// Variadic — single XML document is treated as one element
public function bulk(#[Valid] #[RequestXml] EventDto ...$events): ResponseEntity
// <root><type>order</type><id>42</id></root> → [EventDto]
```

---

## Nested DTOs

All three annotations support the same recursive hydration as `#[RequestBody]`.
Error keys use dot-notation for nested paths.

```php
class SearchDto
{
    public function __construct(
        public readonly string    $query,
        public readonly FilterDto $filter,
    ) {}
}

public function search(#[RequestJson] SearchDto $dto): ResponseEntity
// Body: {"query":"shoes","filter":{"minPrice":10,"maxPrice":500}}
// Error if filter.maxPrice missing:
// {"errors": {"filter.maxPrice": ["is required"]}}
```

---

## Constraint Validation with `#[Valid]`

Works identically to `#[RequestBody]` — add `#[Valid]` alongside the annotation:

```php
class CreateItemDto
{
    public function __construct(
        #[Required] #[NotBlank]
        public readonly string $name,
        #[Min(1)]
        public readonly int    $qty,
    ) {}
}

public function create(#[Valid] #[RequestJson] CreateItemDto $dto): ResponseEntity
```

See [08-validation.md](08-validation.md) for all available constraints.

---

## Comparison

| Annotation       | Forced format                                         |
|------------------|-------------------------------------------------------|
| `#[RequestJson]` | JSON — always `json_decode`, ignores Content-Type     |
| `#[RequestForm]` | Form — `getParsedBody()` + `getQueryParams()`         |
| `#[RequestXml]`  | XML — always `simplexml_load_string`, ignores CT      |
| `#[RequestBody]` | Auto — detects format from `Content-Type` header      |

---

## When to Use

- **`#[RequestJson]`** — API endpoints that only accept JSON; reject other formats explicitly.
- **`#[RequestForm]`** — HTML form submissions or multipart data.
- **`#[RequestXml]`** — XML integrations (SOAP-like, legacy systems, IoT).
- **`#[RequestBody]`** — endpoints that support multiple formats (content negotiation).
