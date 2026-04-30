<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http;

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
        $type     = $param->getType() instanceof ReflectionNamedType ? $param->getType() : null;
        $typeName = $type?->getName();

        // ── #[PathVariable] ───────────────────────────────────────────────────
        if ($attr = $param->getAttributes(PathVariable::class)[0] ?? null) {
            $key = $attr->newInstance()->name ?? $param->getName();
            $val = $pathParams[$key] ?? null;
            return self::required($val, $param, $typeName, "@PathVariable '{$key}'");
        }

        // ── #[RequestParam] ───────────────────────────────────────────────────
        if ($attr = $param->getAttributes(RequestParam::class)[0] ?? null) {
            $key = $attr->newInstance()->name ?? $param->getName();
            $val = $request->getQueryParams()[$key] ?? null;

            if ($val === null) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                if ($type?->allowsNull()) {
                    return null;
                }
                RequestException::throw("Required query parameter '{$key}' is missing");
            }

            return self::cast($val, $typeName);
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
            $name  = $attr->newInstance()->name;
            $files = $request->getUploadedFiles();

            // без имени → вся карта файлов
            if ($name === null) {
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

            // string → содержимое файла в памяти
            if ($typeName === 'string') {
                $path = is_array($file) ? ($file['tmp_name'] ?? '') : (string) $file;
                return file_exists($path) ? file_get_contents($path) : '';
            }

            return $file;
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
            $raw = $attr->newInstance()->name ?? str_replace('_', '-', $param->getName());
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

            return self::cast($val, $typeName);
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
                "Path param '{$param->getName()}'"
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

    private static function cast(mixed $value, ?string $typeName): mixed
    {
        return match ($typeName) {
            'int'    => (int)   $value,
            'float'  => (float) $value,
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'string' => (string) $value,
            default  => $value,
        };
    }

    private static function required(mixed $val, ReflectionParameter $param, ?string $typeName, string $label): mixed
    {
        if ($val !== null) {
            return self::cast($val, $typeName);
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($param->getType()?->allowsNull()) {
            return null;
        }

        RequestException::throw("{$label} is required but missing");
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
