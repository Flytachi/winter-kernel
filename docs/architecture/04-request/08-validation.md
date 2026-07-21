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
              │  • collects ALL structural errors (missing / wrong type)
              │  • when #[Valid]: also runs #[Constraint] checks inline on
              │    each scalar field and cascades into nested DTOs automatically
              ▼
       runValidation()           ← post-hydration pass (only if hydration succeeds)
              │  • re-checks #[Constraint] on each field value of the outer DTO
              │  • cascades into nested objects that have #[Valid] on their field
              │  • cascades into all #[ListOf] collections (implicit)
              ▼
     ValidationException (422)   ← if any error was collected
```

1. `hydrateFromArray` maps body → DTO constructor, collecting **all** structural errors and (when `#[Valid]` is set) scalar constraint violations **in one combined pass**. Nested DTOs are processed recursively with the same `$validate` flag — no extra `#[Valid]` annotation needed on nested fields.
2. If hydration completes without errors, `runValidation` runs a second constraint pass on the constructed object. Nested objects are cascaded when `#[Valid]` appears on the corresponding DTO field.
3. All constraint violations are collected before responding — you see every error at once.
4. Error keys use **dot-notation** for nested DTOs: `"address.city"`, and index notation for collections: `"items[0].name"`.

---

## Usage

```php
use Flytachi\Winter\K2\Http\Request\Validation\{Valid, Required, NotBlank, Min, Max, Email};

class CreateUserDto
{
    public function __construct(
        #[Required] #[NotBlank] #[Size(min: 0, max: 100)]
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

When `#[Valid]` is placed on the **controller parameter**, constraint checking cascades
automatically into all nested DTOs during `hydrateFromArray`. No extra `#[Valid]` is
needed on the nested field itself.

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
        #[NotBlank]
        public readonly string $query,
        public readonly FilterDto $filter,   // ← no #[Valid] needed here
    ) {}
}

// Controller:
public function search(#[Valid] #[RequestBody] SearchDto $dto): ResponseEntity

// Error for nested violation:
// {"errors": {"filter.minPrice": ["must be at least 0"]}}
// {"errors": {"query": ["must not be blank"], "filter.maxPrice": ["must not exceed 1000000"]}}
```

Optionally, place `#[Valid]` on the nested **DTO field** to trigger an additional
post-hydration `runValidation` cascade on that object. This is only needed when you
want `runValidation`-specific behavior (e.g., cross-field checks via `#[Assert]`):

```php
class SearchDto
{
    public function __construct(
        public readonly string $query,
        #[Valid]                         // explicit cascade in runValidation
        public readonly FilterDto $filter,
    ) {}
}
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
| `#[Size(10)]`                  | Exactly 10 chars / items.                           |
| `#[Size(min: 2, max: 100)]`    | Between 2 and 100 chars / items (inclusive).        |
| `#[Size(min: 0, max: 255)]`    | Up to 255 chars / items.                            |

Two forms only — exact (`Size(N)`) or full range (`Size(min, max)`). There is no min-only / max-only shorthand.

`Size` measures: strings → `mb_strlen`, arrays → `count`, numbers → digit count.

### String / Format

| Constraint                        | Description                                       |
|-----------------------------------|---------------------------------------------------|
| `#[Email]`                        | Valid email (RFC 5321 via `filter_var`).           |
| `#[Url]`                          | Valid URL (RFC 2396 via `filter_var`).             |
| `#[Regex('/pattern/')]`           | String must match full PHP regex.                 |
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
#[Required] #[NotBlank] #[Size(min: 0, max: 255)] #[Regex('/^[a-z]+$/')]
public readonly string $slug
```

---

## Custom Error Messages

Every constraint accepts an optional named `message:` parameter that overrides the built-in default.

```php
public function __construct(
    #[Size(min: 0, max: 3, message: 'Name is too long')]
    public readonly string $name,

    #[Min(0, message: 'Quantity cannot be negative')]
    public readonly int $qty,

    #[Email(message: 'Please provide a valid email')]
    public readonly string $email,
) {}
```

The string is returned verbatim in the `errors` map exactly as written.

### i18n Message Keys

Wrap a translation key in `{...}` to resolve it through [`Locale::t()`](../05-localization.md):

```php
#[Size(min: 0, max: 3, message: '{order.name_too_long}')]
public readonly string $name,
```

```php
// lang/ru.php
return [
    'order' => [
        'name_too_long' => 'Поле «:field»: длина не более :max символов',
    ],
];
```

Output: `Поле «name»: длина не более 3 символов`.

**Available placeholders:**

- `:field` — the parameter name (e.g. `name`).
- `:max`, `:min`, `:value`, `:format`, `:integer`, `:fraction`, `:version`, `:pattern`, `:strict`, `:values` — every public property of the constraint becomes a named placeholder. So `Size`, `Min`, `Date`, `Datetime`, `Time`, `Digits`, `In`, `Uuid`, `Regex` etc. expose their own configured values.
- Unknown placeholders are left as-is. Extra params are ignored.
- If the translation key is not found, the framework returns the bare key (e.g. `order.name_too_long`) — never throws.

The resolution happens once, in `ParameterResolver::resolveMessage()` (see `src/Http/ParameterResolver.php:724`), so individual constraint classes know nothing about i18n. Plain strings without `{...}` pass through untouched.

> Tip: prefer named `:placeholder` style in your dictionary entries — `sprintf` (`%s`/`%d`) also works but is positional and harder to maintain.

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

---

## Legacy: `K1ValidationTrait`

`Flytachi\Winter\K2\Http\Request\K1ValidationTrait` is the older, string-rule API. It is kept for backwards compatibility — **prefer the attribute-based system above for any new code**.

Use it on any DTO of your own; there is no base class to extend.

Key differences from the attribute system:

| Aspect | K1ValidationTrait | Attribute system |
|---|---|---|
| Style | Imperative `$this->validate('field', ['rules'])` calls inside `rules()` | Declarative attributes on DTO constructor params |
| Error reporting | Throws `RequestException` on the **first** failure | Collects **all** errors, throws `ValidationException` (422) once |
| Per-rule message | One `$message` overrides every rule on the field | Each `#[Constraint]` has its own `message:` |
| i18n | Same `{key}` syntax (resolved through `Locale::t()`) | Same `{key}` syntax |
| Placeholders in dictionary | Only `:field` | `:field` + every public property of the constraint |

### Available rules

| Rule | Notes |
|---|---|
| `boolean` / `bool` | `is_bool()` |
| `numeric` / `number` | `is_numeric()` |
| `string` / `str` | `is_string()` |
| `array` / `list` | `is_array()` |
| `positive` / `id` | numeric and `> 0` |
| `negative` | numeric and `< 0` |
| `email` | RFC 5321 via `filter_var` |
| `url` | RFC 2396 via `filter_var` |
| `ip` / `ipv4` (`ip4`) / `ipv6` (`ip6`) | `filter_var` with the respective flag |
| `uuid` | RFC 4122 v1–v5 |
| `msisdn` | E.164 with leading `+` |
| `phone` | digits, `+`, spaces, dashes, parens, 7–20 chars |
| `length:min[,max]` / `len:…` | `mb_strlen` of cast string |
| `range:min,max` / `rg:…` | numeric, inclusive |
| `in:a,b,c` | strict comparison after cast to string |
| `datetime[:format]` / `date:…` / `time:…` | `DateTime::createFromFormat`, default format `'Y-m-d H:i:s'` |
| custom `callable` | any closure / `[$obj,'method']` / `'fn'` returning bool — `false` fails |

### Usage

```php
use Flytachi\Winter\K2\Http\Request\K1ValidationTrait;

final class CreateUserRequest
{
    use K1ValidationTrait;

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?int    $age  = null,
        public readonly ?string $email = null,
    ) {}

    public function rules(): void
    {
        $this->validate('name',  ['string', 'length:2,100'])
             ->validate('age',   ['numeric', 'range:0,150'])
             ->validate('email', ['email'], message: '{user.email_invalid}');
    }
}
```

`{user.email_invalid}` is resolved through `Locale::t()` with `:field` set to `'email'` — exactly as in the attribute system.

#### Wildcards (`*`)

A `*` segment in the field path fans the rules out over every element of the
array/object at that position:

```php
$this->validate('staffs.*',               ['array'])
     ->validate('staffs.*.id',            ['number'])
     ->validate('staffs.*.isResponsible', ['bool'], required: false);
```

Rules apply **per existing element**, and failures report the resolved path
(e.g. `staffs.1.id`). If the parent collection is missing or empty, no
element-level checks run — validate the parent itself with a separate
`$this->validate('staffs', ['array'])` when its presence is required.

> **Migration tip:** when you move a `K1ValidationTrait` DTO to `#[Constraint]` attributes, you also gain (a) all-errors-at-once reporting, (b) per-rule `message:` overrides, and (c) richer i18n placeholders (`:max`, `:min`, …).
