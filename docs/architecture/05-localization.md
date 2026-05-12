# Localization

Translates dot-notation keys against a per-locale PHP dictionary file. Picks the active locale from the `Accept-Language` header on every request, with a manual override available.

Coroutine-safe: each Swoole coroutine carries its own locale state. In FPM mode a single per-process instance is used.

---

## Quick start

**1. Bootstrap (once, in your Boot class):**

```php
use Flytachi\Winter\K2\Localization\Locale;

Locale::setBasePath(__DIR__ . '/lang');
Locale::setDefault('en');
```

**2. Add a dictionary file** at `lang/en.php`:

```php
<?php

return [
    'auth' => [
        'unauthorized' => 'Access denied',
        'welcome'      => 'Welcome, %s!',
    ],
    'order' => [
        'created' => 'Order :id has been created',
    ],
];
```

**3. Translate anywhere:**

```php
Locale::t('auth.unauthorized')                   // 'Access denied'
Locale::t('auth.welcome', ['Alice'])             // 'Welcome, Alice!'      (sprintf)
Locale::t('order.created', ['id' => 42])         // 'Order 42 has been created' (named)
trans('auth.welcome', ['Alice'])                 // identical, global helper
```

If no dictionary entry exists, the key is returned unchanged — never throws.

---

## API surface

### `Locale` facade — `src/Localization/Locale.php`

| Method                              | Purpose                                                     |
|-------------------------------------|-------------------------------------------------------------|
| `Locale::setBasePath(string $path)` | Configure root directory of `lang/<lang>.php` files.        |
| `Locale::setDefault(string $lang)`  | Locale used when no `Accept-Language` is sent.              |
| `Locale::initFromRequest()`         | Detect locale from `Accept-Language`. Called by the router. |
| `Locale::set(string $lang)`         | Override the locale for the current request.                |
| `Locale::lang()`                    | Return the active locale code (e.g. `'ru'`).                |
| `Locale::translate(string, array)`  | Resolve a key. Returns the key itself if missing.           |
| `Locale::t(string, array)`          | Shorthand alias for `translate()`.                          |
| `Locale::service()`                 | Access the underlying `LocaleService` directly.             |

### Global helper — `function/dependencies.php`

```php
function trans(string $key, ?array $params = null): string
```

Thin wrapper over `Locale::translate()`. Identical semantics — pick whichever you prefer.

### `LocaleService` — `src/Localization/LocaleService.php`

Loads the dictionary lazily (first `translate()` call). Holds the language code, the base path, and the parsed dictionary. Exposed via `Locale::service()` if you need it directly.

### `LanguageNegotiator` — `src/Localization/LanguageNegotiator.php`

Parses an `Accept-Language` header, ranks entries by `q` quality, and picks the highest-priority locale that the app supports. If `ru-RU` is requested but only `ru` is available, falls back to the base subtag. If nothing matches, returns the default.

```php
LanguageNegotiator::negotiate(
    'ru-RU,ru;q=0.9,en;q=0.8',
    ['en', 'ru'],
    'en',
);
// → 'ru'
```

---

## Dictionary file format

One PHP file per locale: `lang/en.php`, `lang/ru.php`, `lang/kk.php`. The file returns a nested associative array. Keys are accessed via dot notation:

```php
return [
    'auth' => [
        'errors' => [
            'unauthorized' => 'Access denied',
        ],
    ],
];
```

```php
trans('auth.errors.unauthorized'); // 'Access denied'
```

Available locales are auto-discovered by scanning `*.php` in the base path during `initFromRequest()`.

---

## Parameters: two styles, one method

`translate()` auto-detects which style you used based on whether the array is a list (sequential integer keys) or associative (string keys).

### List → `sprintf`

```php
'greet' => 'Hello, %s! You have %d new messages.',

trans('greet', ['Alice', 5]);
// 'Hello, Alice! You have 5 new messages.'
```

Indexed substitution is supported when reordering matters:

```php
'fmt' => 'last=%2$s, first=%1$s',

trans('fmt', ['Ivan', 'Petrov']);
// 'last=Petrov, first=Ivan'
```

### Associative → named `:placeholder`

```php
'order_created' => 'Order :id was created by :user',

trans('order_created', ['id' => 42, 'user' => 'Alice']);
// 'Order 42 was created by Alice'
```

**Why named is usually better:**
- Self-documenting in the dictionary file.
- Order in the translation is free — useful for languages with different word order.
- Extra keys in the params array are ignored (no warnings).
- Unknown placeholders are left intact, not silently dropped.
- Object values are stringified via `__toString()`; `null` becomes an empty string.

An empty array → returns the value untouched.

---

## How the active locale is chosen

1. **Bootstrap** sets the default with `Locale::setDefault('en')`.
2. **Per request**, `Router::handle()` calls `Locale::initFromRequest()` (see `src/Route/Router.php:420`), which:
   - Reads `Accept-Language` from the current request.
   - Scans `<basePath>/*.php` for available locales.
   - Picks the best match via `LanguageNegotiator`.
3. **Application code** can override at any time via `Locale::set('ru')`.
4. If no dictionary file is found for the chosen locale, every call returns the key as-is.

In Swoole mode the `LocaleService` is stored per coroutine context, so concurrent requests never share state. In FPM mode a single static instance is used (one request per process).

---

## Validation integration: `{key}` markers

Constraint attributes accept a custom `message:` parameter. Wrap a translation key in braces and the validation runner resolves it through `Locale::t()` automatically — see [`04-request/08-validation.md`](04-request/08-validation.md#i18n-message-keys) for the full story.

```php
public function __construct(
    #[Size(3, message: '{order.name_too_long}')]
    public string $name,
) {}
```

```php
'order' => [
    'name_too_long' => 'Field «:field»: max length is :max',
],
```

`:field` is the parameter name (`name`); every public property of the constraint (`max`, `min`, …) is also exposed as a named placeholder.

---

## Tests

- `tests/Localization/LocaleServiceTest.php` — dictionary lookup, both parameter styles.
- `tests/Localization/LocaleTest.php` — facade behavior, override, default fallback.
- `tests/Localization/LanguageNegotiatorTest.php` — header parsing, quality ranking, base-subtag fallback.
- `tests/function/TransTest.php` — `trans()` global helper.
