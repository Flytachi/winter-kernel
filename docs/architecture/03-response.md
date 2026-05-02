# Response

## ResponseEntity

Spring Boot-style response wrapper. Controller methods return a `ResponseEntity`; the
Router serializes it to the underlying `HttpResponse` — controllers never call
`$response->end()` themselves.

```php
use Flytachi\Winter\K2\Http\Response\ResponseEntity;

// Static factories
return ResponseEntity::ok($data);                     // 200
return ResponseEntity::created($data);                // 201
return ResponseEntity::noContent();                   // 204
return ResponseEntity::badRequest('message');         // 400
return ResponseEntity::unauthorized();                // 401
return ResponseEntity::forbidden();                   // 403
return ResponseEntity::notFound('message');           // 404
return ResponseEntity::conflict($data);               // 409
return ResponseEntity::unprocessable($data);          // 422
return ResponseEntity::internalError();               // 500

// Custom status
return ResponseEntity::status(HttpCode::ACCEPTED)->body($data);

// Custom headers (chainable)
return ResponseEntity::ok($data)->header('X-Request-Id', $id);
```

## Content Negotiation

When the body is an **array or object**, `ResponseEntity::send()` picks the serialization
format from the client's `Accept` header:

| Accept header         | Format |
|-----------------------|--------|
| `application/json`    | JSON   |
| `application/xml`     | XML    |
| `text/html`           | JSON (API default — HTML views use `ResponseView`) |
| `*/*` or absent       | JSON   |

Scalar values (`string`, `int`, …) are always sent as `text/plain`.

Objects with a `toArray()` method are converted before negotiation.

## Non-ResponseEntity returns

If a controller returns any non-null value that is **not** a `Sendable`, the Router
wraps it automatically:

```php
// Equivalent:
return ['key' => 'value'];
return ResponseEntity::ok(['key' => 'value']);
```

Returning `null` (or `void`) sends no body and no status (connection stays open until
the framework closes it — prefer `ResponseEntity::noContent()` for explicit 204).

## Sendable interface

Implement `Sendable` for custom response types:

```php
use Flytachi\Winter\K2\Http\Response\Sendable;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;

class CsvResponse implements Sendable
{
    public function __construct(private string $csv) {}

    public function send(HttpResponse $response): void
    {
        $response->status(200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->end($this->csv);
    }
}
```

## ResponseView (HTML templates)

`ResponseView` implements `Sendable` and renders PHP templates. It is documented in the
view/template layer.
