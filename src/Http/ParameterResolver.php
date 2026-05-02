<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Base\Tool;
use Flytachi\Winter\DI\ReflectionCache;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Request\Validation\ArrayOf;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestBody;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestFile;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestHeader;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestQuery;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestXml;
use Flytachi\Winter\K2\Http\Request\RequestException;
use Flytachi\Winter\K2\Http\Request\RequestObject;
use Flytachi\Winter\K2\Http\Request\Validation\Constraint;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Request\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Resolves controller method parameters — Spring Boot-style injection.
 *
 * Priority per parameter:
 *   1.  #[PathVariable]   → URL path segment                                   (required unless nullable/default)
 *   2.  #[RequestParam]   → query string (?key=val), camelCase normalized       (required unless nullable/default)
 *   3.  #[RequestBody]    → string: raw | array: by CT | object: hydrated DTO
 *   4.  #[RequestFile]    → multipart upload → array info | string contents
 *   5.  #[RequestJson]    → body forced as JSON → array | stdClass | any class
 *   6.  #[RequestForm]    → body forced as form → array | stdClass | any class
 *   7.  #[RequestXml]     → body forced as XML  → array | stdClass | any class
 *   8.  #[RequestQuery]   → full query string   → array | any class             (always optional)
 *   9.  #[RequestHeader]  → HTTP header, camelCase/snake_case normalized         (required unless nullable/default)
 *   10. Type = HttpRequest (or subclass)  → raw request object injected
 *   11. Type = HttpResponse (or subclass) → raw response object injected
 *   12. Name match in pathParams → path segment without annotation              (required unless nullable/default)
 *   13. PHP default value → used when source is absent
 *   14. Nullable type     → null when source is absent
 *
 * Plain DTO hydration (annotations 3–8):
 *   Any class with a constructor can be used as a DTO — no base class required.
 *   The framework maps source array keys to constructor parameter names via Reflection.
 *   RequestObject subclasses are still supported via their ::fromRequest/json/form/xml/query() methods.
 *
 * Validation:
 *   Add #[Valid] alongside a body/query annotation to trigger constraint validation
 *   after hydration. Constraint attributes (#[Required], #[Min], …) are placed on
 *   the DTO constructor parameters. Failed validation throws ValidationException (422).
 */
class ParameterResolver
{
    public static function resolve(
        ReflectionMethod $method,
        HttpRequest $request,
        HttpResponse $response,
        array $pathParams,
    ): array {
        $args = [];
        foreach (ReflectionCache::parameters($method->class, $method->name) as $param) {
            $args[] = self::resolveParam($param, $request, $response, $pathParams);
        }
        return $args;
    }

    // ── Resolution pipeline ───────────────────────────────────────────────────

    private static function resolveParam(
        ReflectionParameter $param,
        HttpRequest $request,
        HttpResponse $response,
        array $pathParams,
    ): mixed {
        $value = self::doResolve($param, $request, $response, $pathParams);

        if (is_object($value) && $param->getAttributes(Valid::class)) {
            self::runValidation($value);
        }

        return $value;
    }

    private static function doResolve(
        ReflectionParameter $param,
        HttpRequest $request,
        HttpResponse $response,
        array $pathParams,
    ): mixed {
        $paramType = $param->getType();
        if ($paramType !== null && !$paramType instanceof ReflectionNamedType) {
            throw new \LogicException(sprintf(
                "Union/intersection type on '\$%s' in %s::%s() is not supported for HTTP parameter binding — use a single type",
                $param->getName(),
                $param->getDeclaringClass()?->getName(),
                $param->getDeclaringFunction()->getName(),
            ));
        }
        $type     = $paramType;
        $typeName = $type?->getName();

        // ── #[PathVariable] ───────────────────────────────────────────────────
        if ($attr = $param->getAttributes(PathVariable::class)[0] ?? null) {
            $key = $attr->newInstance()->name ?? $param->getName();
            $val = $pathParams[$key] ?? null;
            return self::required($val, $param, $typeName, "Path variable '$key'");
        }

        // ── #[RequestParam] ───────────────────────────────────────────────────
        if ($attr = $param->getAttributes(RequestParam::class)[0] ?? null) {
            $instance    = $attr->newInstance();
            $queryParams = $request->getQueryParams();
            if ($instance->name !== null) {
                $key = $instance->name;
                $val = $queryParams[$key] ?? null;
            } else {
                $key = $param->getName();
                $val = $queryParams[$key]
                    ?? $queryParams[Tool::camelToSnake($key)]
                    ?? $queryParams[Tool::camelToKebab($key)]
                    ?? null;
            }

            if ($val === null) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                if ($type?->allowsNull()) {
                    return null;
                }
                RequestException::throw("Required query parameter '{$key}' is missing");
            }

            return self::cast($val, $typeName, "Query parameter '{$key}'");
        }

        // ── #[RequestBody] ────────────────────────────────────────────────────
        if ($param->getAttributes(RequestBody::class)[0] ?? null) {
            $raw = $request->getRawBody() ?? '';
            $ct  = strtolower($request->getHeader('content-type') ?? '');

            if ($typeName === 'string') {
                return $raw;
            }
            if ($typeName === 'array') {
                if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
                    $xml = @simplexml_load_string($raw);
                    return $xml ? (json_decode(json_encode($xml), true) ?: []) : [];
                }
                return json_decode($raw, true) ?? [];
            }
            if ($typeName === 'object' || $typeName === \stdClass::class) {
                if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
                    $xml = @simplexml_load_string($raw);
                    return $xml ? json_decode(json_encode($xml)) : new \stdClass();
                }
                return json_decode($raw) ?? new \stdClass();
            }
            if ($typeName !== null && class_exists($typeName)) {
                // variadic: JSON array → collection of DTOs
                if ($param->isVariadic()) {
                    $items = json_decode($raw, true);
                    if (!is_array($items)) {
                        RequestException::throw('Expected JSON array for variadic RequestBody');
                    }
                    if (is_subclass_of($typeName, RequestObject::class)) {
                        /** @var class-string<RequestObject> $typeName */
                        return array_map(static fn(array $item) => $typeName::fromArray($item), $items);
                    }
                    return array_map(static fn(array $item) => self::hydrateFromArray($item, $typeName), $items);
                }
                // single DTO — auto-detect Content-Type
                if (is_subclass_of($typeName, RequestObject::class)) {
                    /** @var class-string<RequestObject> $typeName */
                    return $typeName::fromRequest($request);
                }
                $data = str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')
                    ? (($xml = @simplexml_load_string($raw)) ? json_decode(json_encode($xml), true) ?? [] : [])
                    : (json_decode($raw, true) ?? []);
                return self::hydrateFromArray($data, $typeName);
            }
            throw new \LogicException(
                "#[RequestBody] parameter '\${$param->getName()}' has unsupported type '{$typeName}'"
            );
        }

        // ── #[RequestFile] ────────────────────────────────────────────────────
        if ($attr = $param->getAttributes(RequestFile::class)[0] ?? null) {
            $annotation = $attr->newInstance();
            $name       = $annotation->name;
            $files      = $request->getUploadedFiles();

            if ($name === null) {
                if ($annotation->maxSize !== null || $annotation->accept !== []) {
                    foreach ($files as $fieldName => $file) {
                        $entries = isset($file[0]) && is_array($file[0]) ? $file : [$file];
                        foreach ($entries as $entry) {
                            self::validateFile($entry, (string) $fieldName, $annotation);
                        }
                    }
                }
                return $files;
            }

            $file = $files[$name] ?? null;
            if ($file === null) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                if ($type?->allowsNull()) {
                    return null;
                }
                RequestException::throw("Uploaded file '{$name}' is missing");
            }

            if ($annotation->multiple) {
                $list = isset($file[0]) && is_array($file[0]) ? $file : [$file];
                foreach ($list as $entry) {
                    self::validateFile($entry, $name, $annotation);
                }
                return $list;
            }

            $single = isset($file[0]) && is_array($file[0]) ? $file[0] : $file;
            self::validateFile($single, $name, $annotation);

            if ($typeName === 'string') {
                $path = is_array($single) ? ($single['tmp_name'] ?? '') : (string) $single;
                if (!file_exists($path)) {
                    RequestException::throw("Uploaded file '{$name}' is not readable");
                }
                return file_get_contents($path);
            }

            return $single;
        }

        // ── #[RequestJson] — force JSON parse ────────────────────────────────
        if ($param->getAttributes(RequestJson::class)[0] ?? null) {
            $raw  = $request->getRawBody() ?? '';
            $data = json_decode($raw, true) ?? [];
            if ($typeName === 'array') {
                return $data;
            }
            if ($typeName === 'object' || $typeName === \stdClass::class) {
                return json_decode($raw) ?? new \stdClass();
            }
            if ($typeName !== null && class_exists($typeName)) {
                if (is_subclass_of($typeName, RequestObject::class)) {
                    /** @var class-string<RequestObject> $typeName */
                    return $typeName::json($request);
                }
                return self::hydrateFromArray($data, $typeName);
            }
            throw new \LogicException(
                "#[RequestJson] parameter '\${$param->getName()}' has unsupported type '{$typeName}'"
            );
        }

        // ── #[RequestForm] — force form parse ────────────────────────────────
        if ($param->getAttributes(RequestForm::class)[0] ?? null) {
            $data = $request->getParsedBody() + $request->getQueryParams();
            if ($typeName === 'array') {
                return $data;
            }
            if ($typeName === 'object' || $typeName === \stdClass::class) {
                return (object) $data;
            }
            if ($typeName !== null && class_exists($typeName)) {
                if (is_subclass_of($typeName, RequestObject::class)) {
                    /** @var class-string<RequestObject> $typeName */
                    return $typeName::form($request);
                }
                return self::hydrateFromArray($data, $typeName);
            }
            throw new \LogicException(
                "#[RequestForm] parameter '\${$param->getName()}' has unsupported type '{$typeName}'"
            );
        }

        // ── #[RequestXml] — force XML parse ──────────────────────────────────
        if ($param->getAttributes(RequestXml::class)[0] ?? null) {
            $raw = $request->getRawBody() ?? '';
            $xml = @simplexml_load_string($raw);
            if ($typeName === 'array') {
                return $xml ? (json_decode(json_encode($xml), true) ?: []) : [];
            }
            if ($typeName === 'object' || $typeName === \stdClass::class) {
                return $xml ? json_decode(json_encode($xml)) : new \stdClass();
            }
            if ($typeName !== null && class_exists($typeName)) {
                if (is_subclass_of($typeName, RequestObject::class)) {
                    /** @var class-string<RequestObject> $typeName */
                    return $typeName::xml($request);
                }
                $data = $xml ? (json_decode(json_encode($xml), true) ?: []) : [];
                return self::hydrateFromArray($data, $typeName);
            }
            throw new \LogicException(
                "#[RequestXml] parameter '\${$param->getName()}' has unsupported type '{$typeName}'"
            );
        }

        // ── #[RequestQuery] — full query string ───────────────────────────────
        if ($param->getAttributes(RequestQuery::class)[0] ?? null) {
            $data = $request->getQueryParams();
            if ($typeName === 'array') {
                return $data;
            }
            if ($typeName === 'object' || $typeName === \stdClass::class) {
                return (object) $data;
            }
            if ($typeName !== null && class_exists($typeName)) {
                if (is_subclass_of($typeName, RequestObject::class)) {
                    /** @var class-string<RequestObject> $typeName */
                    return $typeName::query($request, required: false);
                }
                return self::hydrateFromArray($data, $typeName);
            }
            throw new \LogicException(
                "#[RequestQuery] parameter '\${$param->getName()}' has unsupported type '{$typeName}'"
            );
        }

        // ── #[RequestHeader] ─────────────────────────────────────────────────
        if ($attr = $param->getAttributes(RequestHeader::class)[0] ?? null) {
            $raw = $attr->newInstance()->name ?? str_replace('_', '-', Tool::camelToKebab($param->getName()));
            $val = $request->getHeader($raw);

            if ($val === null) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                if ($type?->allowsNull()) {
                    return null;
                }
                RequestException::throw("Required header '{$raw}' is missing");
            }

            return self::cast($val, $typeName, "Header '{$raw}'");
        }

        // ── Framework types ───────────────────────────────────────────────────
        if ($typeName !== null && is_a($typeName, HttpRequest::class, true)) {
            return $request;
        }
        if ($typeName !== null && is_a($typeName, HttpResponse::class, true)) {
            return $response;
        }

        // ── Path param by name (no annotation) ───────────────────────────────
        if (array_key_exists($param->getName(), $pathParams)) {
            return self::required(
                $pathParams[$param->getName()],
                $param,
                $typeName,
                "Path variable '{$param->getName()}'"
            );
        }

        // ── Default / nullable fallback ───────────────────────────────────────
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($type?->allowsNull()) {
            return null;
        }

        throw new \RuntimeException(sprintf(
            "Cannot resolve parameter '\$%s' in %s::%s() — add an annotation or a default value",
            $param->getName(),
            $param->getDeclaringClass()?->getName(),
            $param->getDeclaringFunction()->getName(),
        ));
    }

    // ── DTO hydration ─────────────────────────────────────────────────────────

    /**
     * Constructs any class from an associative array — single pass, all errors collected.
     *
     * Nested objects (class-typed fields with array values) are hydrated recursively.
     * All missing / type errors are gathered before throwing so the caller sees
     * everything at once, not just the first failure.
     *
     * Error keys use dot-notation for nested paths: "filter.minPrice".
     *
     * @template T of object
     * @param class-string<T> $typeName
     * @return T
     * @throws ValidationException when any field is missing or has wrong type
     */
    private static function hydrateFromArray(array $data, string $typeName, string $prefix = ''): object
    {
        $errors = [];
        $args   = [];

        foreach (ReflectionCache::parameters($typeName, '__construct') as $param) {
            $name      = $param->getName();
            $pType     = $param->getType() instanceof ReflectionNamedType ? $param->getType() : null;
            $pTypeName = $pType?->getName();
            $key       = $prefix !== '' ? "$prefix.$name" : $name;

            if (array_key_exists($name, $data)) {
                $val = $data[$name];

                if ($val === null) {
                    if ($pType?->allowsNull()) {
                        $args[] = null;
                        self::checkConstraints($param, null, $key, $errors);
                    } else {
                        $errors[$key][] = 'must not be null';
                        $args[] = null;
                    }
                } elseif ($pTypeName !== null && class_exists($pTypeName) && !self::isCastableClass($pTypeName)) {
                    if (is_array($val)) {
                        try {
                            $nested = self::hydrateFromArray($val, $pTypeName, $key);
                            $args[] = $nested;
                        } catch (ValidationException $e) {
                            foreach ($e->getErrors() as $k => $msgs) {
                                $errors[$k] = isset($errors[$k]) ? array_merge($errors[$k], $msgs) : $msgs;
                            }
                            $args[] = null;
                        }
                    } else {
                        $errors[$key][] = 'must be an object, got ' . get_debug_type($val);
                        $args[] = null;
                    }
                } elseif ($pTypeName === 'array'
                    && ($arrayAttr = $param->getAttributes(ArrayOf::class)[0] ?? null)
                ) {
                    $elementClass = $arrayAttr->newInstance()->class;
                    if (!is_array($val)) {
                        $errors[$key][] = 'must be an array';
                        $args[] = [];
                    } else {
                        // Normalize XML-style wrapper: SimpleXML folds repeated child elements
                        // under a single string key, e.g. ['item'=>[...]] or ['item'=>{...}].
                        // Unwrap when the array has exactly one non-integer key whose value is
                        // itself an array (list → already the collection; assoc → single item).
                        if (!array_is_list($val) && count($val) === 1) {
                            $inner = reset($val);
                            $val   = is_array($inner) && array_is_list($inner) ? $inner : [$inner];
                        }
                        $collection = [];
                        foreach ($val as $i => $item) {
                            $itemKey = "{$key}[{$i}]";
                            if (!is_array($item)) {
                                $errors[$itemKey][] = 'must be an object';
                                continue;
                            }
                            try {
                                $collection[] = self::hydrateFromArray($item, $elementClass, $itemKey);
                            } catch (ValidationException $e) {
                                foreach ($e->getErrors() as $k => $msgs) {
                                    $errors[$k] = isset($errors[$k]) ? array_merge($errors[$k], $msgs) : $msgs;
                                }
                            }
                        }
                        $args[] = $collection;
                        self::checkConstraints($param, $collection, $key, $errors);
                    }
                } else {
                    try {
                        $resolved = self::cast($val, $pTypeName, $name);
                        $args[] = $resolved;
                        self::checkConstraints($param, $resolved, $key, $errors);
                    } catch (\Throwable $e) {
                        $errors[$key][] = $e->getMessage();
                        $args[] = null;
                    }
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($pType?->allowsNull()) {
                $args[] = null;
            } else {
                $errors[$key][] = 'is required';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new $typeName(...$args);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Runs #[Constraint] checks on all constructor params of the DTO.
     * If a param carries #[Valid] and its value is an object, recurses into it.
     * All violations are collected before throwing — single ValidationException
     * with dot-notation keys for nested paths.
     */
    private static function runValidation(object $value, string $prefix = ''): void
    {
        $errors = [];

        foreach (ReflectionCache::parameters($value::class, '__construct') as $param) {
            $name       = $param->getName();
            $fieldValue = $value->{$name};
            $key        = $prefix !== '' ? "$prefix.$name" : $name;

            // #[Valid] on a nested DTO field → recurse
            if (is_object($fieldValue) && $param->getAttributes(Valid::class)) {
                try {
                    self::runValidation($fieldValue, $key);
                } catch (ValidationException $e) {
                    foreach ($e->getErrors() as $k => $msgs) {
                        $errors[$k] = isset($errors[$k]) ? array_merge($errors[$k], $msgs) : $msgs;
                    }
                }
            }

            // Constraint attributes on this param
            foreach ($param->getAttributes() as $attr) {
                $instance = $attr->newInstance();
                if ($instance instanceof Constraint) {
                    if ($msg = $instance->validate($fieldValue, $name)) {
                        $errors[$key][] = $msg;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** Runs all #[Constraint] attributes on a single parameter, appends failures to $errors. */
    private static function checkConstraints(
        \ReflectionParameter $param,
        mixed $value,
        string $key,
        array &$errors,
    ): void {
        foreach ($param->getAttributes() as $attr) {
            $instance = $attr->newInstance();
            if ($instance instanceof Constraint) {
                if ($msg = $instance->validate($value, $param->getName())) {
                    $errors[$key][] = $msg;
                }
            }
        }
    }

    // ── Scalar casting ────────────────────────────────────────────────────────

    private static function cast(mixed $value, ?string $typeName, string $label = 'Parameter'): mixed
    {
        if (is_array($value) && $typeName !== null && $typeName !== 'array') {
            RequestException::throw("$label must be $typeName, got array");
        }

        return match (true) {
            $typeName === 'int' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value
                : RequestException::throw("$label must be an integer, got '$value'"),
            $typeName === 'float' => filter_var($value, FILTER_VALIDATE_FLOAT) !== false
                ? (float) $value
                : RequestException::throw("$label must be a float, got '$value'"),
            $typeName === 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ?? RequestException::throw("$label must be a boolean (true/false/1/0/yes/no), got '$value'"),
            $typeName === 'string' => (string) $value,
            $typeName === 'array' => is_array($value)
                ? $value
                : RequestException::throw("$label must be an array (use bracket notation: key[]=val), got '$value'"),
            $typeName !== null && enum_exists($typeName)
                && is_subclass_of($typeName, \BackedEnum::class) => self::castEnum($value, $typeName, $label),
            $typeName === \DateTimeImmutable::class => self::castDateTimeImmutable($value, $label),
            $typeName === \DateTime::class          => self::castDateTime($value, $label),
            $typeName === 'BcMath\Number' && extension_loaded('bcmath')  => self::castBcMathNumber($value, $label),
            $typeName === 'Decimal\Decimal' && extension_loaded('decimal') => self::castDecimal($value, $label),
            default => $value,
        };
    }

    /** @param class-string<\BackedEnum> $typeName */
    private static function castEnum(mixed $value, string $typeName, string $label): \BackedEnum
    {
        $backingType = ReflectionCache::enumOf($typeName)->getBackingType()?->getName();
        if ($backingType === 'int') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                $valid = implode(', ', array_column($typeName::cases(), 'value'));
                RequestException::throw("$label must be one of [$valid], got '$value'");
            }
            $value = (int) $value;
        }
        try {
            return $typeName::from($value);
        } catch (\ValueError) {
            $valid = implode(', ', array_column($typeName::cases(), 'value'));
            RequestException::throw("$label must be one of [$valid], got '$value'");
        }
    }

    private static function castDateTimeImmutable(mixed $value, string $label): \DateTimeImmutable
    {
        $str = (string) $value;
        if ($str === '') {
            RequestException::throw("$label has invalid date '' — expected ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)");
        }
        try {
            return new \DateTimeImmutable($str);
        } catch (\Exception) {
            RequestException::throw("$label has invalid date '$value' — expected ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)");
        }
    }

    private static function castDateTime(mixed $value, string $label): \DateTime
    {
        $str = (string) $value;
        if ($str === '') {
            RequestException::throw("$label has invalid date '' — expected ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)");
        }
        try {
            return new \DateTime($str);
        } catch (\Exception) {
            RequestException::throw("$label has invalid date '$value' — expected ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)");
        }
    }

    private static function castBcMathNumber(mixed $value, string $label): \BcMath\Number
    {
        if (!is_numeric($value)) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
        try {
            return new \BcMath\Number((string) $value);
        } catch (\ValueError) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
    }

    private static function castDecimal(mixed $value, string $label): \Decimal\Decimal
    {
        if (!is_numeric($value)) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
        try {
            return new \Decimal\Decimal((string) $value);
        } catch (\Throwable) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
    }

    // ── Misc helpers ──────────────────────────────────────────────────────────

    /**
     * Returns true for class types that cast() knows how to build from a scalar:
     * BackedEnum, DateTime, DateTimeImmutable, BcMath\Number, Decimal\Decimal.
     * All other class types are treated as plain DTOs and expect an array.
     */
    private static function isCastableClass(string $typeName): bool
    {
        return (enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class))
            || $typeName === \DateTimeImmutable::class
            || $typeName === \DateTime::class
            || ($typeName === 'BcMath\Number' && extension_loaded('bcmath'))
            || ($typeName === 'Decimal\Decimal' && extension_loaded('decimal'));
    }

    private static function required(mixed $val, ReflectionParameter $param, ?string $typeName, string $label): mixed
    {
        if ($val !== null) {
            return self::cast($val, $typeName, $label);
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($param->getType()?->allowsNull()) {
            return null;
        }
        RequestException::throw("$label is missing");
    }

    private static function validateFile(array $file, string $fieldName, RequestFile $annotation): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            RequestException::throw("Uploaded file '{$fieldName}' transfer error (code {$error})");
        }
        if ($annotation->maxSize !== null) {
            $limit = self::parseSize($annotation->maxSize);
            if (($file['size'] ?? 0) > $limit) {
                RequestException::throw("Uploaded file '{$fieldName}' exceeds maximum size of {$annotation->maxSize}");
            }
        }
        if ($annotation->accept !== []) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'] ?? '');
            if (!self::mimeAccepted($mime ?: '', $file['name'] ?? '', $annotation->accept)) {
                $allowed = implode(', ', $annotation->accept);
                RequestException::throw("Uploaded file '{$fieldName}' type '{$mime}' is not allowed (accepted: {$allowed})");
            }
        }
    }

    private static function parseSize(string $size): int
    {
        preg_match('/^(\d+(?:\.\d+)?)\s*(B|KB|MB|GB)?$/i', trim($size), $m);
        $value = (float) ($m[1] ?? 0);
        return (int) match (strtoupper($m[2] ?? 'B')) {
            'KB'    => $value * 1_024,
            'MB'    => $value * 1_048_576,
            'GB'    => $value * 1_073_741_824,
            default => $value,
        };
    }

    private static function mimeAccepted(string $mime, string $filename, array $accept): bool
    {
        foreach ($accept as $pattern) {
            if (str_starts_with($pattern, '.')) {
                $ext = '.' . strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if ($ext === strtolower($pattern)) {
                    return true;
                }
            } elseif (str_ends_with($pattern, '/*')) {
                if (str_starts_with($mime, substr($pattern, 0, -1))) {
                    return true;
                }
            } elseif ($mime === $pattern) {
                return true;
            }
        }
        return false;
    }
}
