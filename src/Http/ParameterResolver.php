<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http;

use Flytachi\Winter\Base\Tool;
use Flytachi\Winter\DI\ReflectionCache;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
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
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Resolves controller method parameters — Spring Boot-style injection.
 *
 * Priority per parameter:
 *   1. #[PathVariable]   → URL path params                                   (required unless nullable/default)
 *   2. #[RequestParam]   → query string (?key=val)                           (required unless nullable/default)
 *   3. #[RequestBody]    → string: raw body | array: parsed by CT | RequestObject: auto-detect
 *   4. #[RequestFile]    → uploaded file (multipart) → array info | string contents
 *   5. #[RequestJson]    → force JSON body → RequestObject::json()
 *   6. #[RequestForm]    → force form body → RequestObject::form()
 *   7. #[RequestXml]     → force XML body  → RequestObject::xml()
 *   8. #[RequestQuery]   → query DTO → RequestObject::query()                (optional)
 *   8. #[RequestHeader]  → request header                                    (required unless nullable/default)
 *   9. Type = HttpRequest (or subclass)  → raw request object
 *  10. Type = HttpResponse (or subclass) → raw response object
 *  11. Name in pathParams → path param without annotation                    (auto-cast)
 *  12. Default value     → use it
 *  13. Nullable type     → null
 *  14. (none matched)    → RuntimeException (misconfigured controller)
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

    private static function resolveParam(
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
            $instance = $attr->newInstance();
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

        // ── #[RequestBody] — raw string / object / array / RequestObject ───────
        if ($param->getAttributes(RequestBody::class)[0] ?? null) {
            $raw = $request->getRawBody() ?? '';
            $ct  = strtolower($request->getHeader('content-type') ?? '');

            // string → raw body (text или binary, PHP string бинарно-безопасен)
            if ($typeName === 'string') {
                return $raw;
            }

            // object / stdClass → json_decode без ассоциативного массива
            if ($typeName === 'object' || $typeName === \stdClass::class) {
                if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
                    $xml = @simplexml_load_string($raw);
                    return $xml ? json_decode(json_encode($xml)) : new \stdClass();
                }
                return json_decode($raw) ?? new \stdClass();
            }

            // array → парсим по Content-Type: JSON или XML
            if ($typeName === 'array') {
                if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
                    $xml = @simplexml_load_string($raw);
                    return $xml ? (json_decode(json_encode($xml), true) ?: []) : [];
                }
                return json_decode($raw, true) ?? [];
            }

            // variadic RequestObject (MyDto ...$items) → JSON-массив в коллекцию DTO
            if (
                $param->isVariadic() && $typeName !== null
                && is_subclass_of($typeName, RequestObject::class)
            ) {
                $items = json_decode($raw, true);
                if (!is_array($items)) {
                    RequestException::throw('Expected JSON array for variadic RequestBody');
                }
                /** @var class-string<RequestObject> $typeName */
                return array_map(static fn(array $item) => $typeName::fromArray($item), $items);
            }

            // RequestObject subclass → auto-detect Content-Type
            self::assertRequestObject($typeName, $param->getName(), 'RequestBody');
            /** @var class-string<RequestObject> $typeName */
            return $typeName::fromRequest($request);
        }

        // ── #[RequestFile] — загруженный файл из multipart ───────────────────
        if ($attr = $param->getAttributes(RequestFile::class)[0] ?? null) {
            /** @var RequestFile $annotation */
            $annotation = $attr->newInstance();
            $name       = $annotation->name;
            $files      = $request->getUploadedFiles();

            // без имени → вся карта файлов (constraints применяются к каждому)
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
                // normalize single → list
                $list = isset($file[0]) && is_array($file[0]) ? $file : [$file];
                foreach ($list as $entry) {
                    self::validateFile($entry, $name, $annotation);
                }
                return $list;
            }

            // single file — take first if client sent multiple
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

        // ── #[RequestJson] — явно JSON → RequestObject ────────────────────────
        if ($param->getAttributes(RequestJson::class)[0] ?? null) {
            self::assertRequestObject($typeName, $param->getName(), 'RequestJson');
            /** @var class-string<RequestObject> $typeName */
            return $typeName::json($request);
        }

        // ── #[RequestForm] — form → RequestObject ─────────────────────────────
        if ($param->getAttributes(RequestForm::class)[0] ?? null) {
            self::assertRequestObject($typeName, $param->getName(), 'RequestForm');
            /** @var class-string<RequestObject> $typeName */
            return $typeName::form($request);
        }

        // ── #[RequestXml] — XML → RequestObject ─────────────────────────────
        if ($param->getAttributes(RequestXml::class)[0] ?? null) {
            self::assertRequestObject($typeName, $param->getName(), 'RequestXml');
            /** @var class-string<RequestObject> $typeName */
            return $typeName::xml($request);
        }

        // ── #[RequestQuery] — query DTO → RequestObject ───────────────────────
        if ($param->getAttributes(RequestQuery::class)[0] ?? null) {
            self::assertRequestObject($typeName, $param->getName(), 'RequestQuery');
            /** @var class-string<RequestObject> $typeName */
            return $typeName::query($request, required: false);
        }

        // ── #[RequestHeader] ─────────────────────────────────────────────────
        if ($attr = $param->getAttributes(RequestHeader::class)[0] ?? null) {
            $raw = $attr->newInstance()->name ?? str_replace('_', '-',
                Tool::camelToKebab($param->getName())
            );
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

        // ── Framework types (HttpRequest / HttpResponse or any subclass) ──────
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function cast(mixed $value, ?string $typeName, string $label = 'Parameter'): mixed
    {
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
                ? $value : RequestException::throw(
                    "$label must be an array (use bracket notation: key[]=val), got '$value'"
                ),
            $typeName !== null && enum_exists($typeName)
                && is_subclass_of($typeName, \BackedEnum::class) => self::castEnum($value, $typeName, $label),
            $typeName === \DateTimeImmutable::class => self::castDateTimeImmutable($value, $label),
            $typeName === \DateTime::class => self::castDateTime($value, $label),
            $typeName === 'BcMath\Number' && extension_loaded('bcmath')
                => self::castBcMathNumber($value, $label),
            $typeName === 'Decimal\Decimal' && extension_loaded('decimal')
                => self::castDecimal($value, $label),
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

    private static function validateFile(array $file, string $fieldName, \Flytachi\Winter\K2\Http\Request\Annotation\RequestFile $annotation): void
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
            $mime = new \finfo(FILEINFO_MIME_TYPE)->file($file['tmp_name'] ?? '');
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

    private static function assertRequestObject(?string $typeName, string $paramName, string $annotation): void
    {
        if ($typeName === null || !is_subclass_of($typeName, RequestObject::class)) {
            throw new \LogicException(
                "#[{$annotation}] parameter '\${$paramName}' must be typed as a RequestObject subclass"
            );
        }
    }
}
