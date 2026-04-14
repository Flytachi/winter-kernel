<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request;

use ArgumentCountError;
use DateTime;
use Error;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use TypeError;

/**
 * Typed request DTO — works in both Swoole and FPM modes via HttpRequest.
 *
 * Usage:
 *   class UserCreateRequest extends RequestObject {
 *       public function __construct(
 *           public readonly string $name,
 *           public readonly string $email,
 *           public readonly int    $age = 0,
 *       ) {}
 *
 *       public function rules(): void {
 *           $this->validate('name',  ['string', 'length:1,100'])
 *                ->validate('email', ['email'])
 *                ->validate('age',   ['numeric', 'range:0,150'], required: false);
 *       }
 *   }
 *
 *   // In controller (injected via #[RequestBody]):
 *   public function store(#[RequestBody] UserCreateRequest $body): ResponseEntity { ... }
 */
abstract class RequestObject
{
    // ── Factory methods ───────────────────────────────────────────────────────

    /** Build from JSON body (Content-Type: application/json). */
    final public static function json(HttpRequest $request, bool $required = true): static
    {
        $raw = $request->getRawBody();
        if ($required && (!$raw || !json_validate($raw))) {
            RequestException::throw('Missing or invalid JSON body');
        }
        $data = $raw ? (json_decode($raw, true) ?? []) : [];
        return self::make($data);
    }

    /** Build from XML body (Content-Type: application/xml | text/xml). */
    final public static function xml(HttpRequest $request, bool $required = true): static
    {
        $raw = $request->getRawBody();
        if ($required && !$raw) {
            RequestException::throw('Missing XML body');
        }
        $xml = $raw ? @simplexml_load_string($raw) : false;
        if ($required && $xml === false) {
            RequestException::throw('Invalid XML body');
        }
        $data = $xml ? (json_decode(json_encode($xml), true) ?: []) : [];
        return self::make($data);
    }

    /** Build from URL-encoded / multipart form data. */
    final public static function form(HttpRequest $request, bool $required = true): static
    {
        $data = $request->getParsedBody();
        if ($required && empty($data)) {
            RequestException::throw('Missing required form data');
        }
        return self::make($data);
    }

    /** Build directly from an associative array (e.g. one item from a JSON array). */
    final public static function fromArray(array $data, bool $required = true): static
    {
        if ($required && empty($data)) {
            RequestException::throw('Empty data array');
        }
        return self::make($data);
    }

    /** Build from query string parameters. */
    final public static function query(HttpRequest $request, bool $required = true): static
    {
        $data = $request->getQueryParams();
        if ($required && empty($data)) {
            RequestException::throw('Missing required query parameters');
        }
        return self::make($data);
    }

    /**
     * Auto-select source by Content-Type header.
     * Falls back to JSON for unknown content types.
     */
    final public static function fromRequest(HttpRequest $request, bool $required = true): static
    {
        $ct = strtolower($request->getHeader('content-type') ?? '');

        if (str_contains($ct, 'application/json')) {
            return static::json($request, $required);
        }

        if (str_contains($ct, 'multipart/form-data')
            || str_contains($ct, 'application/x-www-form-urlencoded')
        ) {
            return static::form($request, $required);
        }

        if (str_contains($ct, 'application/xml')
            || str_contains($ct, 'text/xml')
        ) {
            return static::xml($request, $required);
        }

        return static::json($request, $required);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Override in subclasses to declare validation rules.
     * Called automatically after construction.
     *
     *   public function rules(): void {
     *       $this->validate('email', ['email'])
     *            ->validate('age',   ['numeric', 'range:0,150'], required: false);
     *   }
     */
    public function rules(): void {}

    /**
     * Validate a field using rule strings or callables.
     *
     * Rules: 'boolean', 'numeric', 'string', 'array',
     *        'length:min,max', 'range:min,max', 'in:a,b,c',
     *        'email', 'url', 'uuid', 'ip', 'ipv4', 'ipv6',
     *        'msisdn', 'phone', 'datetime[:format]', 'positive', 'negative'
     *
     * @param array<callable|string> $rules
     */
    final protected function validate(
        string  $field,
        array   $rules,
        bool    $required = true,
        ?string $message  = null,
    ): static {
        $value = $this->get($field);

        if (!$required && $value === null) {
            return $this;
        }

        if ($required && $value === null && !property_exists($this, $field)) {
            RequestException::throw($message ?? "Required field '{$field}' not found");
        }

        foreach ($rules as $rule) {
            if (is_callable($rule)) {
                if (!$rule($value)) {
                    RequestException::throw($message ?? "Field '{$field}' failed custom validation");
                }
                continue;
            }

            [$ruleName, $params] = str_contains($rule, ':')
                ? [strtok($rule, ':'), explode(',', substr($rule, strpos($rule, ':') + 1))]
                : [$rule, []];

            $this->applyRule($field, $value, $ruleName, $params, $message);
        }

        return $this;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function make(array $data): static
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            $normalized[self::dashToCamel($key)] = $value;
        }

        if (!empty($normalized)) {
            $normalized = self::castToConstructorTypes($normalized);
        }

        try {
            $instance = empty($normalized) ? new static() : new static(...$normalized);
            $instance->rules();
            return $instance;
        } catch (ArgumentCountError $e) {
            $msg = preg_replace(
                '/.*Argument #\d+ \(\$(\w+)\) not passed.*/',
                "Required field '\$1' not found",
                $e->getMessage()
            ) ?: 'Missing required data';
            RequestException::throw($msg, previous: $e);
        } catch (TypeError $e) {
            $msg = preg_replace(
                '/.*Argument #\d+ \(\$(\w+)\) must be of type (\S+), (\S+) given.*/',
                "Invalid type for '\$1' (expected: '\$2', got: '\$3')",
                $e->getMessage()
            ) ?: $e->getMessage();
            RequestException::throw($msg, previous: $e);
        } catch (Error $e) {
            $msg = preg_replace(
                '/Unknown named parameter \$(\w+)/',
                "Unknown field '\$1'",
                $e->getMessage()
            ) ?: $e->getMessage();
            RequestException::throw($msg, previous: $e);
        }
    }

    private function get(string $field): mixed
    {
        $parts  = explode('.', $field);
        $target = $this;

        foreach ($parts as $part) {
            if (is_object($target) && property_exists($target, $part)) {
                $target = $target->{$part};
            } elseif (is_array($target) && array_key_exists($part, $target)) {
                $target = $target[$part];
            } else {
                return null;
            }
        }

        return $target;
    }

    private static function castToConstructorTypes(array $data): array
    {
        $constructor = (new \ReflectionClass(static::class))->getConstructor();
        if ($constructor === null) return $data;

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (!array_key_exists($name, $data)) continue;

            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) continue;

            $value = $data[$name];
            if ($value === null) continue;

            $data[$name] = match ($type->getName()) {
                'int'    => (int)   $value,
                'float'  => (float) $value,
                'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
                'string' => (string) $value,
                default  => $value,
            };
        }

        return $data;
    }

    private static function dashToCamel(string $key): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $key))));
    }

    // ── Built-in rules ────────────────────────────────────────────────────────

    private function applyRule(string $field, mixed $value, string $rule, array $params, ?string $msg): void
    {
        $fail = static fn(string $m) => RequestException::throw($msg ?? $m);

        match ($rule) {
            'boolean', 'bool'          => is_bool($value) || $fail("Field '{$field}' must be boolean"),
            'numeric', 'number'        => is_numeric($value) || $fail("Field '{$field}' must be numeric"),
            'string', 'str'            => is_string($value) || $fail("Field '{$field}' must be a string"),
            'array', 'list'            => is_array($value) || $fail("Field '{$field}' must be an array"),
            'positive', 'id'           => (is_numeric($value) && $value > 0)
                                              || $fail("Field '{$field}' must be positive"),
            'negative'                 => (is_numeric($value) && $value < 0)
                                              || $fail("Field '{$field}' must be negative"),
            'email'                    => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL))
                                              || $fail("Field '{$field}' must be a valid email"),
            'url'                      => (is_string($value) && filter_var($value, FILTER_VALIDATE_URL))
                                              || $fail("Field '{$field}' must be a valid URL"),
            'ip'                       => (is_string($value) && filter_var($value, FILTER_VALIDATE_IP))
                                              || $fail("Field '{$field}' must be a valid IP"),
            'ipv4', 'ip4'              => (is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                                              || $fail("Field '{$field}' must be a valid IPv4"),
            'ipv6', 'ip6'              => (is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
                                              || $fail("Field '{$field}' must be a valid IPv6"),
            'uuid'                     => (is_string($value) && preg_match(
                                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                                              $value)) || $fail("Field '{$field}' must be a valid UUID"),
            'msisdn'                   => (is_string($value) && preg_match('/^\+[1-9]\d{6,14}$/', $value))
                                              || $fail("Field '{$field}' must be a valid MSISDN (E.164)"),
            'phone'                    => (is_string($value) && preg_match('/^\+?\d[\d\s\-\(\)]{5,20}$/', $value))
                                              || $fail("Field '{$field}' must be a valid phone number"),
            'length', 'len'            => $this->ruleLength($field, $value, $params, $fail),
            'range', 'rg'              => $this->ruleRange($field, $value, $params, $fail),
            'in'                       => $this->ruleIn($field, $value, $params, $fail),
            'datetime', 'date', 'time' => $this->ruleDatetime($field, $value, $params, $fail),

            default => RequestException::throw("Unknown validation rule '{$rule}'"),
        };
    }

    private function ruleLength(string $field, mixed $value, array $p, callable $fail): void
    {
        $len = mb_strlen((string) $value);
        $min = (int) ($p[0] ?? 0);
        $max = (int) ($p[1] ?? $min);
        if ($len < $min || $len > $max) {
            $fail("Field '{$field}' length must be between {$min} and {$max}");
        }
    }

    private function ruleRange(string $field, mixed $value, array $p, callable $fail): void
    {
        if (!is_numeric($value)) {
            $fail("Field '{$field}' must be numeric for 'range' rule");
            return;
        }
        $v = (float) $value;
        if ($v < (float) ($p[0] ?? 0) || $v > (float) ($p[1] ?? PHP_INT_MAX)) {
            $fail("Field '{$field}' must be in range {$p[0]} – {$p[1]}");
        }
    }

    private function ruleIn(string $field, mixed $value, array $p, callable $fail): void
    {
        if (!in_array((string) $value, $p, strict: true)) {
            $fail("Field '{$field}' must be one of: " . implode(', ', $p));
        }
    }

    private function ruleDatetime(string $field, mixed $value, array $p, callable $fail): void
    {
        $format = $p[0] ?? 'Y-m-d H:i:s';
        if (!is_string($value)) {
            $fail("Field '{$field}' must be a datetime string");
            return;
        }
        $dt     = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if (!$dt || ($errors && ($errors['error_count'] > 0 || $errors['warning_count'] > 0))) {
            $fail("Field '{$field}' must match format {$format}");
        }
    }
}
