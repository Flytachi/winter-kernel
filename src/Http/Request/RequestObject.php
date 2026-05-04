<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request;

use Flytachi\Winter\K2\Http\Contracts\HttpRequest;

/**
 * Typed request DTO — works in both Swoole and FPM modes via HttpRequest.
 *
 * @deprecated Use plain readonly classes with #[Constraint] attributes instead.
 *   Validation rules have moved to K1ValidationTrait for backwards compatibility.
 */
#[\Deprecated]
abstract class RequestObject
{
    use K1ValidationTrait;

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
        if (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) {
            return static::form($request, $required);
        }
        if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
            return static::xml($request, $required);
        }

        return static::json($request, $required);
    }

    /** Override to declare validation rules — called automatically after construction. */
    public function rules(): void
    {
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
        } catch (\ArgumentCountError $e) {
            $msg = preg_replace(
                '/.*Argument #\d+ \(\$(\w+)\) not passed.*/',
                "Required field '\$1' not found",
                $e->getMessage()
            ) ?: 'Missing required data';
            RequestException::throw($msg, previous: $e);
        } catch (\TypeError $e) {
            $msg = preg_replace(
                '/.*Argument #\d+ \(\$(\w+)\) must be of type (\S+), (\S+) given.*/',
                "Invalid type for '\$1' (expected: '\$2', got: '\$3')",
                $e->getMessage()
            ) ?: $e->getMessage();
            RequestException::throw($msg, previous: $e);
        } catch (\Error $e) {
            $msg = preg_replace(
                '/Unknown named parameter \$(\w+)/',
                "Unknown field '\$1'",
                $e->getMessage()
            ) ?: $e->getMessage();
            RequestException::throw($msg, previous: $e);
        }
    }

    private static function castToConstructorTypes(array $data): array
    {
        $constructor = (new \ReflectionClass(static::class))->getConstructor();
        if ($constructor === null) {
            return $data;
        }

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (!array_key_exists($name, $data)) {
                continue;
            }
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
                continue;
            }
            $value = $data[$name];
            if ($value === null) {
                continue;
            }
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
}
