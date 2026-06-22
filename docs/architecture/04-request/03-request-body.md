# `#[RequestBody]`

Binds the raw request body to a controller method parameter.
The format is auto-detected from the `Content-Type` header unless the type is `string`.

```php
use Flytachi\Winter\K2\Http\Request\Annotation\RequestBody;
```

---

## Rules

1. Only one `#[RequestBody]` parameter per method is meaningful.
2. For `string` — always returns raw bytes, ignores `Content-Type`.
3. For `array` / `object` / `stdClass` — parsed by `Content-Type` (JSON or XML).
4. For any class — hydrated from parsed body via constructor (named parameter injection).
5. For variadic class — expects a JSON array, maps each element to a DTO instance.
6. Nested class-typed constructor parameters are hydrated recursively.

---

## Supported Types

### `string` — raw body
```php
public function webhook(#[RequestBody] string $payload): ResponseEntity
// Returns raw bytes regardless of Content-Type
```

### `array` — parsed by Content-Type
```php
public function create(#[RequestBody] array $data): ResponseEntity
// application/json → json_decode($raw, true)
// application/xml  → simplexml → array
```

### `object` / `stdClass` — decoded object
```php
public function create(#[RequestBody] object $data): ResponseEntity
// application/json → json_decode($raw)         → stdClass
// application/xml  → simplexml → json → decode → stdClass
```

---

## Plain DTO Hydration (preferred)

Any class with a constructor can be used as a DTO — no base class required.
The framework maps JSON/XML keys to constructor parameter names via reflection.
Missing required parameters → `422 Unprocessable Entity` with a field → messages map.

```php
class CreateOrderDto
{
    public function __construct(
        public readonly string $title,
        public readonly int    $amount,
        public readonly ?string $note = null,
    ) {}
}

public function create(#[RequestBody] CreateOrderDto $dto): ResponseEntity
// Body: {"title":"Widget","amount":5}
// → CreateOrderDto{title:"Widget", amount:5, note:null}
```

### Missing field error (422)
```json
// Body: {"title":"Widget"}
{
  "code": 422,
  "message": "Validation failed",
  "errors": {
    "amount": ["is required"]
  }
}
```

### Wrong type error (422)
```json
// Body: {"title":"Widget","amount":"not-a-number"}
{
  "errors": {
    "amount": ["amount must be an integer, got 'not-a-number'"]
  }
}
```

---

## Nested DTO Hydration

Class-typed constructor parameters whose type is not a scalar-castable class
(enum, DateTime, BcMath\Number, etc.) are hydrated recursively from the matching
sub-object. Error keys use **dot-notation** to identify the nested path.

```php
class AddressDto
{
    public function __construct(
        public readonly string $city,
        public readonly string $zip,
    ) {}
}

class CreateUserDto
{
    public function __construct(
        public readonly string     $name,
        public readonly AddressDto $address,
    ) {}
}

public function register(#[RequestBody] CreateUserDto $dto): ResponseEntity
// Body: {"name":"Alice","address":{"city":"Berlin","zip":"10115"}}
// → CreateUserDto{name:"Alice", address:AddressDto{city:"Berlin",zip:"10115"}}
```

### Nested missing field error (dot notation)
```json
// Body: {"name":"Alice","address":{"city":"Berlin"}}
{
  "errors": {
    "address.zip": ["is required"]
  }
}
```

---

## All Errors at Once

The hydrator collects **all** errors in a single pass before responding.
You see every missing/invalid field in one response, not just the first one.

```json
// Body: {}
{
  "errors": {
    "name":         ["is required"],
    "address.city": ["is required"],
    "address.zip":  ["is required"]
  }
}
```

---

## Constraint Validation with `#[Valid]`

Add `#[Valid]` alongside `#[RequestBody]` to run constraint annotations
declared on DTO constructor parameters after hydration.
Failed constraints throw `ValidationException (422)`.

```php
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Request\Validation\Required;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Min;
use Flytachi\Winter\K2\Http\Request\Validation\Max;

class CreateOrderDto
{
    public function __construct(
        #[Required] #[NotBlank]
        public readonly string $title,

        #[Required] #[Min(1)] #[Max(10_000)]
        public readonly int $amount,
    ) {}
}

public function create(
    #[Valid] #[RequestBody] CreateOrderDto $dto,
): ResponseEntity { ... }
```

See [08-validation.md](08-validation.md) for the full list of constraints.

---

## Variadic — JSON Array → Collection

Add `...` to map a JSON array of objects to a PHP array of DTOs.
A JSON object `{...}` (not a list) is rejected with `400`.

```php
public function bulk(#[RequestBody] CreateOrderDto ...$items): ResponseEntity
// Body: [{"title":"A","amount":1}, {"title":"B","amount":2}]
// → [CreateOrderDto, CreateOrderDto]
// Non-array / JSON object → 400: "Expected JSON array for variadic body"

public function bulk(#[Valid] #[RequestBody] CreateOrderDto ...$items): ResponseEntity
// → each element is also validated; errors reported with [0].field keys
```

Errors across all elements are collected before responding:
```json
{
  "errors": {
    "[0].title": ["is required"],
    "[1].amount": ["must be at least 1"]
  }
}
```

---

## Single-field mode — `field:`

Pass `field` to pull **one value** out of the body instead of hydrating the whole payload —
useful when you only need a field or two and don't want a dedicated DTO. `Content-Type`
detection still runs first to decode the body (JSON or XML), then the field is extracted and
cast to the parameter type, exactly like a query param or header.

```php
public function rename(#[RequestBody(field: 'name'), Size(5, 40)] string $name): ResponseEntity
// {"name":"Jonathan", ...}  → "Jonathan"
// <root><name>Jonathan</name></root> (Content-Type: application/xml) → "Jonathan"
```

It behaves like any other **scalar source**:

- **Required by default** — a missing field or explicit `null` throws `400 Required Body field 'name' is missing`, unless the parameter is nullable (`?T`) or has a default value.
- **`#[Constraint]` fires automatically** — no `#[Valid]` needed (`Size(5, 40)` above runs on its own).
- Falsy-but-present values (`0`, `false`, `""`, `[]`) are **not** treated as missing.
- The value is cast/hydrated to the declared type: scalar, enum, `DateTime`, `BcMath\Number`, `array`, `stdClass`, a DTO (add `#[Valid]` to cascade), or a variadic DTO list.
- **Nested fields** use dot-notation: `field: 'filter.minPrice'` walks into sub-objects; a missing segment counts as a missing field.

The same `field:` option works on `#[RequestJson]`, `#[RequestForm]` and `#[RequestXml]` —
see [07-request-json-form-xml.md](07-request-json-form-xml.md#single-field-mode--field).

