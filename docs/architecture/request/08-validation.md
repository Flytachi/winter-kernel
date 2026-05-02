# Request Validation — `#[Valid]` + Constraints

Winter K2 provides an attribute-based validation system for request DTOs.
Constraints are PHP attributes placed on DTO constructor parameters.
Validation is triggered by adding `#[Valid]` to the controller method parameter.

```php
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
```

---

## How It Works

```
#[Valid] #[RequestBody] CreateOrderDto $dto
              │
              ▼
     hydrateFromArray()          ← builds DTO from request body
              │                    collects missing/type errors (422)
              ▼
       runValidation()           ← reads #[Constraint] attributes
              │                    on each constructor parameter
              ▼
     ValidationException (422)   ← if any constraint fails
```

1. `hydrateFromArray` maps request body → DTO constructor, collecting ALL structural errors.
2. If hydration succeeds, `runValidation` runs every `#[Constraint]` attribute on every field.
3. All constraint violations are collected before responding — you see every error at once.
4. Error keys use **dot-notation** for nested DTOs: `"filter.minPrice"`.

---

## Usage

```php
use Flytachi\Winter\K2\Http\Request\Validation\{Valid, Required, NotBlank, Min, Max, Email};

class CreateUserDto
{
    public function __construct(
        #[Required] #[NotBlank] #[Size(max: 100)]
        public readonly string $name,

        #[Required] #[Email]
        public readonly string $email,

        #[Min(0)] #[Max(150)]
        public readonly int $age,
    ) {}
}

// In controller:
public function register(
    #[Valid] #[RequestBody] CreateUserDto $dto,
): ResponseEntity { ... }
```

---

## Error Response Format

Failed validation returns **422 Unprocessable Entity**:

```json
{
  "code": 422,
  "message": "Validation failed",
  "errors": {
    "name":  ["is required"],
    "email": ["must be a valid email address"],
    "age":   ["must be at least 0", "must not exceed 150"]
  }
}
```

- Key = field name (dot-notation for nested: `"filter.minPrice"`)
- Value = array of error message strings (short, without field name)
- Multiple errors per field are all reported

---

## Nested DTO Validation

Place `#[Valid]` on a nested DTO field to trigger recursive validation:

```php
class FilterDto
{
    public function __construct(
        #[Min(0)]
        public readonly int $minPrice,
        #[Max(1_000_000)]
        public readonly int $maxPrice,
    ) {}
}

class SearchDto
{
    public function __construct(
        public readonly string $query,
        #[Valid]
        public readonly FilterDto $filter,
    ) {}
}

// Error for nested violation:
// {"errors": {"filter.minPrice": ["must be at least 0"]}}
```

---

## All Constraints

### Presence

| Constraint    | Description                                               |
|---------------|-----------------------------------------------------------|
| `#[Required]` | Fails if value is `null`. Use for nullable/default fields.|
| `#[NotBlank]` | Fails if string is empty or whitespace-only. null passes. |

### Numeric

| Constraint          | Description                                              |
|---------------------|----------------------------------------------------------|
| `#[Min(n)]`         | Value must be ≥ n. Supports int, float, BcMath, Decimal. |
| `#[Max(n)]`         | Value must be ≤ n. Supports int, float, BcMath, Decimal. |
| `#[Positive]`       | Value must be > 0.                                       |
| `#[PositiveOrZero]` | Value must be ≥ 0.                                       |
| `#[Negative]`       | Value must be < 0.                                       |
| `#[NegativeOrZero]` | Value must be ≤ 0.                                       |
| `#[Digits(integer: n, fraction: m)]` | Integer part ≤ n digits, fraction ≤ m digits. |

### Size / Length

| Constraint                     | Description                                         |
|--------------------------------|-----------------------------------------------------|
| `#[Size(10)]`                  | Max 10 chars / items (shorthand for max only).      |
| `#[Size(min: 2, max: 100)]`    | Between 2 and 100 chars / items.                    |
| `#[Size(min: 1)]`              | At least 1 char / item.                             |

`Size` measures: strings → `mb_strlen`, arrays → `count`, numbers → digit count.

### String / Format

| Constraint                        | Description                                       |
|-----------------------------------|---------------------------------------------------|
| `#[Email]`                        | Valid email (RFC 5321 via `filter_var`).           |
| `#[Url]`                          | Valid URL (RFC 2396 via `filter_var`).             |
| `#[Regex('/pattern/')]`           | String must match full PHP regex.                 |
| `#[Regex('/p/', 'custom msg')]`   | With custom error message.                        |
| `#[In(['a', 'b', 'c'])]`         | Value must be in the list (strict by default).    |
| `#[In([1, 2], strict: false)]`   | Loose comparison.                                 |
| `#[Uuid]`                         | Valid UUID (any version, RFC 4122).               |
| `#[Uuid(4)]`                      | UUID v4 only.                                     |

### Phone / Network

| Constraint   | Description                                                       |
|--------------|-------------------------------------------------------------------|
| `#[Ip]`      | Valid IP address (IPv4 or IPv6).                                  |
| `#[Ipv4]`    | Valid IPv4 address only.                                          |
| `#[Ipv6]`    | Valid IPv6 address only.                                          |
| `#[Msisdn]`  | MSISDN (E.164 without `+`): digits only, 7–15 chars.             |
| `#[Phone]`   | Phone number: `+`, digits, spaces, dashes, parens, 7–20 chars.   |

### Date / Time

| Constraint                      | Description                                             |
|---------------------------------|---------------------------------------------------------|
| `#[Date]`                       | Valid date string in `Y-m-d` format.                    |
| `#[Date('d.m.Y')]`              | Custom date format.                                     |
| `#[Time]`                       | Valid time string in `H:i` or `H:i:s`.                  |
| `#[Time('H:i')]`                | Strict `H:i` only.                                      |
| `#[Datetime]`                   | Any ISO 8601 string parseable by `DateTimeImmutable`.   |
| `#[Datetime('Y-m-d H:i:s')]`   | Strict custom datetime format.                          |

### Custom

| Constraint                              | Description                                          |
|-----------------------------------------|------------------------------------------------------|
| `#[Assert('ClassName::method')]`        | Custom callable: `fn(mixed $value, string $field): ?string`. |
| `#[Assert('globalFunction')]`           | Any globally callable string. Repeatable.            |

---

## `#[Required]` vs Non-Nullable Fields

PHP already enforces non-nullable constructor parameters — if the key is missing
from the request body, `hydrateFromArray` reports `"is required"` automatically.

`#[Required]` adds value only for **nullable** or **defaulted** parameters where
you want to explicitly require the field to be present:

```php
public function __construct(
    string  $name,              // ← PHP enforces this, no #[Required] needed
    ?string $email = null,      // ← #[Required] needed if you want it mandatory
    array   $tags  = [],        // ← #[Required] needed if you want it mandatory
) {}
```

---

## Constraint Behaviour: null

All constraints except `#[Required]` **skip `null` values** — they only validate
non-null values. Combine with `#[Required]` to also reject null:

```php
#[Required]                // fails if null
#[NotBlank]                // fails if null passes through (but Required already catches it)
#[Min(1)]                  // fails if value < 1, null passes
public readonly ?int $qty
```

---

## Multiple Constraints Per Field

Stack multiple attributes — all are checked, all failures reported:

```php
#[Required] #[NotBlank] #[Size(max: 255)] #[Regex('/^[a-z]+$/')]
public readonly string $slug
```

---

## Custom Callable — `#[Assert]`

For validation logic that doesn't fit a built-in constraint:

```php
class OrderRules
{
    public static function validateAmount(mixed $value, string $field): ?string
    {
        if (!is_int($value) || $value % 100 !== 0) {
            return 'must be a positive multiple of 100';
        }
        return null;
    }
}

class CreateOrderDto
{
    public function __construct(
        #[Assert('App\Rules\OrderRules::validateAmount')]
        public readonly int $amount,
    ) {}
}
```

`#[Assert]` is **repeatable** — stack multiple custom validators on one field.
The callable signature must be: `function(mixed $value, string $field): ?string`.
Return `null` to pass, return a string to fail with that message.
