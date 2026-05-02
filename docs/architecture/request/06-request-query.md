# `#[RequestQuery]`

Binds the entire query string as a typed DTO or raw array.
Use when multiple query parameters form a logical group (filters, pagination, search).

```php
use Flytachi\Winter\K2\Http\Request\Annotation\RequestQuery;
```

---

## Rules

1. Supported types: `array` or any class with a constructor.
2. Always optional — missing or empty query string produces an empty array / default-filled DTO.
3. Does **not** support `#[Valid]` directly (use `#[RequestParam]` per field if validation is needed).

---

## Supported Types

### `array` — raw query params

```php
public function list(#[RequestQuery] array $params): ResponseEntity
// GET /items?page=2&sort=name → ['page' => '2', 'sort' => 'name']
```

### `stdClass` / `object` — raw query params as object

```php
public function list(#[RequestQuery] \stdClass $params): ResponseEntity
// GET /items?page=2&sort=name → stdClass{page:"2", sort:"name"}
```

### Plain DTO — hydrated from query params

Any class with a constructor. Parameters with defaults are optional; missing
required parameters result in `422 Unprocessable Entity`.

```php
class OrderFilter
{
    public function __construct(
        public readonly int     $page   = 1,
        public readonly int     $limit  = 20,
        public readonly ?string $search = null,
    ) {}
}

#[GetMapping('orders')]
public function list(#[RequestQuery] OrderFilter $filter): ResponseEntity
// GET /orders?page=2&limit=10&search=hello
// → OrderFilter(page: 2, limit: 10, search: "hello")

// GET /orders  (no query string)
// → OrderFilter(page: 1, limit: 20, search: null)   ← all defaults
```

---

## Type Casting

Query string values are strings from the HTTP layer.
The hydrator casts them to the constructor parameter type automatically:

```php
class SearchDto
{
    public function __construct(
        public readonly int              $page   = 1,
        public readonly float            $minRating = 0.0,
        public readonly bool             $active = true,
        public readonly ?string          $q      = null,
        public readonly ?StatusEnum      $status = null,
        public readonly ?\DateTimeImmutable $from = null,
    ) {}
}
```

Supported casts match `#[RequestParam]` — see [02-request-param.md](02-request-param.md).

---

## When to Use vs `#[RequestParam]`

| Scenario                                      | Use                  |
|-----------------------------------------------|----------------------|
| 1–2 independent query params                  | `#[RequestParam]`    |
| 3+ params forming a filter / search / paging  | `#[RequestQuery]`    |
| Reusable query DTO shared across methods      | `#[RequestQuery]`    |
| Per-field validation (`#[Min]`, `#[Email]`)   | `#[RequestParam]`    |
