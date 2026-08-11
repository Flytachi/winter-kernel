<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http;

use BcMath\Number as BcNumber;
use BackedEnum;
use DateTime;
use DateTimeImmutable;
use Decimal\Decimal;
use Exception;
use finfo;
use Flytachi\Winter\Base\Tool;
use Flytachi\Winter\DI\ReflectionCache;
use Flytachi\Winter\Kernel\Http\Contracts\HttpRequest;
use Flytachi\Winter\Kernel\Http\Contracts\HttpResponse;
use Flytachi\Winter\Kernel\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestBody;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestFile;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestHeader;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestParam;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestQuery;
use Flytachi\Winter\Kernel\Http\Request\Annotation\RequestXml;
use Flytachi\Winter\Kernel\Http\Request\RequestException;
use Flytachi\Winter\Kernel\Http\Request\Validation\ListOf;
use Flytachi\Winter\Kernel\Http\Request\Validation\Constraint;
use Flytachi\Winter\Kernel\Http\Request\Validation\Valid;
use Flytachi\Winter\Kernel\Http\Request\Validation\ValidationException;
use Flytachi\Winter\Kernel\Localization\Locale;
use LogicException;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use stdClass;
use Throwable;
use ValueError;

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
 *       (#[RequestBody/Json/Form/Xml] all accept field: 'x' → extract & cast one field, scalar-style)
 *   8.  #[RequestQuery]   → full query string   → array | any class             (always optional)
 *   9.  #[RequestHeader]  → HTTP header, camelCase/snake_case normalized         (required unless nullable/default)
 *   10. Type = HttpRequest (or subclass)  → raw request object injected
 *   11. Type = HttpResponse (or subclass) → raw response object injected
 *   12. Name match in pathParams → path segment without annotation              (required unless nullable/default)
 *   13. PHP default value → used when source is absent
 *   14. Nullable type     → null when source is absent
 *
 * Validation:
 *   - Scalar params (#[RequestParam], #[PathVariable], #[RequestHeader],
 *     and #[RequestBody/Json/Form/Xml(field: …)]): #[Constraint] fires automatically — no #[Valid] needed.
 *   - Object / DTO params:
 *     Add #[Valid] to trigger #[Constraint] validation after hydration.
 *     Without #[Valid], only structural errors (missing/wrong-type fields) are reported.
 *   - #[ListOf] collections cascade constraints when #[Valid] is on the outer param.
 *   - Variadic params: #[Valid] validates each element; all errors collected with [i].field keys.
 */
final class ParameterResolver
{
    /**
     * Declared types that accept an array, so binding one to them is not an error.
     *
     * `mixed` and `iterable` are here because PHP itself accepts an array for both; the
     * absence of `object` is deliberate, since an array bound to it would only fail later
     * at construction, and failing here says why.
     */
    private const array ARRAY_COMPATIBLE = ['array', 'mixed', 'iterable'];

    // ── Public API ────────────────────────────────────────────────────────────

    public static function resolve(
        ReflectionMethod $method,
        HttpRequest $request,
        HttpResponse $response,
        array $pathParams,
    ): array {
        $args = [];
        foreach (ReflectionCache::parameters($method->class, $method->name) as $param) {
            $value = self::resolveParam($param, $request, $response, $pathParams);
            if ($param->isVariadic() && is_array($value)) {
                array_push($args, ...$value);
            } else {
                $args[] = $value;
            }
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
        $withConstraints = !empty($param->getAttributes(Valid::class));
        $value = self::doResolve($param, $request, $response, $pathParams, $withConstraints);

        if (is_object($value) && $withConstraints) {
            self::runValidation($value);
        } elseif (!$param->isVariadic()) {
            self::validateScalar($param, $value);
        }

        return $value;
    }

    private static function doResolve(
        ReflectionParameter $param,
        HttpRequest $request,
        HttpResponse $response,
        array $pathParams,
        bool $withConstraints,
    ): mixed {
        $type = $param->getType();
        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw new LogicException(sprintf(
                "Union/intersection type on '\$%s' in %s::%s() is not supported for "
                . "HTTP parameter binding — use a single type",
                $param->getName(),
                $param->getDeclaringClass()?->getName(),
                $param->getDeclaringFunction()->getName(),
            ));
        }
        /** @var ReflectionNamedType|null $type */
        $typeName = $type?->getName();

        if ($attr = $param->getAttributes(PathVariable::class)[0] ?? null) {
            return self::resolvePathVariable($attr, $param, $typeName, $pathParams);
        }
        if ($attr = $param->getAttributes(RequestParam::class)[0] ?? null) {
            return self::resolveRequestParam($attr, $param, $type, $typeName, $request);
        }
        if ($param->getAttributes(RequestBody::class)) {
            return self::resolveRequestBody($param, $type, $typeName, $request, $withConstraints);
        }
        if ($attr = $param->getAttributes(RequestFile::class)[0] ?? null) {
            return self::resolveRequestFile($attr, $param, $type, $typeName, $request);
        }
        if ($param->getAttributes(RequestJson::class)) {
            return self::resolveRequestJson($param, $type, $typeName, $request, $withConstraints);
        }
        if ($param->getAttributes(RequestForm::class)) {
            return self::resolveRequestForm($param, $type, $typeName, $request, $withConstraints);
        }
        if ($param->getAttributes(RequestXml::class)) {
            return self::resolveRequestXml($param, $type, $typeName, $request, $withConstraints);
        }
        if ($param->getAttributes(RequestQuery::class)) {
            return self::resolveRequestQuery($param, $typeName, $request, $withConstraints);
        }
        if ($attr = $param->getAttributes(RequestHeader::class)[0] ?? null) {
            return self::resolveRequestHeader($attr, $param, $type, $typeName, $request);
        }

        return self::resolveDefault($param, $type, $typeName, $request, $response, $pathParams);
    }

    // ── Annotation handlers ───────────────────────────────────────────────────

    private static function resolvePathVariable(
        ReflectionAttribute $attr,
        ReflectionParameter $param,
        ?string $typeName,
        array $pathParams,
    ): mixed {
        $key = $attr->newInstance()->name ?? $param->getName();
        return self::required($pathParams[$key] ?? null, $param, $typeName, "Path variable '$key'");
    }

    private static function resolveRequestParam(
        ReflectionAttribute $attr,
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
    ): mixed {
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
            RequestException::throw("Required query parameter '$key' is missing");
        }

        return self::cast($val, $typeName, "Query parameter '$key'");
    }

    private static function resolveRequestBody(
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
        bool $withConstraints,
    ): mixed {
        $raw = $request->getRawBody() ?? '';
        $ct  = strtolower($request->getHeader('content-type') ?? '');

        $field = $param->getAttributes(RequestBody::class)[0]->newInstance()->field;
        if ($field !== null) {
            return self::resolveField(
                $param,
                $type,
                $typeName,
                $field,
                self::parseBodyAsArray($ct, $raw, $request),
                'Body field',
                $withConstraints,
            );
        }

        if ($typeName === 'string') {
            return $raw;
        }
        if ($typeName === 'array') {
            return self::parseBodyAsArray($ct, $raw, $request);
        }
        if ($typeName === null || $typeName === 'object' || $typeName === stdClass::class) {
            return self::parseBodyAsObject($ct, $raw, $request);
        }
        if (!class_exists($typeName)) {
            throw new LogicException(sprintf(
                "Request body parameter '\$%s' has unsupported type '%s'",
                $param->getName(),
                $typeName,
            ));
        }
        if ($param->isVariadic()) {
            return self::resolveVariadicBody($raw, $typeName, $withConstraints);
        }

        return self::hydrateFromArray(self::parseBodyAsArray($ct, $raw, $request), $typeName, '', $withConstraints);
    }

    private static function resolveVariadicBody(string $raw, string $typeName, bool $withConstraints): array
    {
        $items = json_decode($raw, true);
        if (!is_array($items) || !array_is_list($items)) {
            RequestException::throw('Expected JSON array for variadic body');
        }
        $errors = [];
        $result = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                $errors["[$i]"][] = 'must be an object';
                continue;
            }
            try {
                $obj = self::hydrateFromArray($item, $typeName, "[$i]", $withConstraints);
                if ($withConstraints) {
                    self::runValidation($obj, "[$i]");
                }
                $result[] = $obj;
            } catch (ValidationException $e) {
                self::mergeErrors($errors, $e->getErrors());
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $result;
    }

    private static function resolveVariadicXml(array $data, string $typeName, bool $withConstraints): array
    {
        if (!array_is_list($data)) {
            $data = [$data];
        }
        $errors = [];
        $result = [];
        foreach ($data as $i => $item) {
            if (!is_array($item)) {
                $errors["[$i]"][] = 'must be an object';
                continue;
            }
            try {
                $obj = self::hydrateFromArray($item, $typeName, "[$i]", $withConstraints);
                if ($withConstraints) {
                    self::runValidation($obj, "[$i]");
                }
                $result[] = $obj;
            } catch (ValidationException $e) {
                self::mergeErrors($errors, $e->getErrors());
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $result;
    }

    private static function resolveRequestFile(
        ReflectionAttribute $attr,
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
    ): mixed {
        /** @var RequestFile $annotation */
        $annotation = $attr->newInstance();
        $name  = $annotation->name;
        $files = $request->getUploadedFiles();

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
            RequestException::throw("Uploaded file '$name' is missing");
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
                RequestException::throw("Uploaded file '$name' is not readable");
            }
            return file_get_contents($path);
        }

        return $single;
    }

    private static function resolveRequestJson(
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
        bool $withConstraints,
    ): mixed {
        $raw   = $request->getRawBody() ?? '';
        $field = $param->getAttributes(RequestJson::class)[0]->newInstance()->field;

        if ($field !== null) {
            $decoded = json_decode($raw, true);
            return self::resolveField(
                $param,
                $type,
                $typeName,
                $field,
                is_array($decoded) ? $decoded : [],
                'JSON field',
                $withConstraints,
            );
        }

        $data = json_decode($raw, true) ?? [];

        if ($typeName === 'array') {
            return $data;
        }
        if ($typeName === 'object' || $typeName === stdClass::class) {
            return json_decode($raw) ?? new stdClass();
        }
        if ($typeName !== null && class_exists($typeName)) {
            if ($param->isVariadic()) {
                return self::resolveVariadicBody($raw, $typeName, $withConstraints);
            }
            return self::hydrateFromArray($data, $typeName, '', $withConstraints);
        }
        throw new LogicException(sprintf(
            "Request JSON body parameter '\$%s' has unsupported type '%s'",
            $param->getName(),
            $typeName,
        ));
    }

    /**
     * Extracts a single value from a decoded body by (dot-notation) field path and
     * casts it to the parameter type. Shared by #[RequestJson], #[RequestBody],
     * #[RequestForm] and #[RequestXml]. Acts like a scalar source: required by default,
     * optional via a nullable type or PHP default value; an absent field and an
     * explicit null are treated the same. #[Constraint] attributes fire automatically
     * on scalar fields (handled by validateScalar in resolveParam).
     *
     * @param array<string,mixed> $data   Decoded body to read from.
     * @param string              $source Human label for errors, e.g. "JSON field".
     */
    private static function resolveField(
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        string $field,
        array $data,
        string $source,
        bool $withConstraints,
    ): mixed {
        [$found, $val] = self::digJsonField($data, $field);

        if (!$found || $val === null) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            if ($type?->allowsNull()) {
                return null;
            }
            RequestException::throw("Required $source '$field' is missing");
        }

        $label = "$source '$field'";

        if ($typeName === 'array') {
            return is_array($val)
                ? $val
                : RequestException::throw("$label must be an array");
        }
        if ($typeName === 'object' || $typeName === stdClass::class) {
            return is_array($val)
                ? json_decode(json_encode($val))
                : RequestException::throw("$label must be an object");
        }
        if (self::isHydratableClass($typeName)) {
            if (!is_array($val)) {
                RequestException::throw("$label must be an object");
            }
            if ($param->isVariadic()) {
                return self::resolveVariadicBody(json_encode($val), $typeName, $withConstraints);
            }
            return self::hydrateFromArray($val, $typeName, $field, $withConstraints);
        }

        return self::cast($val, $typeName, $label);
    }

    /**
     * Walks a dot-notation path into a decoded JSON array.
     *
     * @return array{0: bool, 1: mixed} [found, value] — found is false if any
     *                                  segment is missing or a non-array is traversed.
     */
    private static function digJsonField(array $data, string $field): array
    {
        $cursor = $data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return [false, null];
            }
            $cursor = $cursor[$segment];
        }
        return [true, $cursor];
    }

    /**
     * True when $typeName is a user class that should be hydrated from an array,
     * as opposed to a scalar-castable type (enum, DateTime, BcMath\Number, Decimal).
     */
    private static function isHydratableClass(?string $typeName): bool
    {
        static $castable = [
            DateTime::class,
            DateTimeImmutable::class,
            'BcMath\Number',
            'Decimal\Decimal',
        ];
        return $typeName !== null
            && class_exists($typeName)
            && !enum_exists($typeName)
            && !in_array($typeName, $castable, true);
    }

    private static function resolveRequestForm(
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
        bool $withConstraints,
    ): mixed {
        $data = $request->getParsedBody() + $request->getQueryParams();

        $field = $param->getAttributes(RequestForm::class)[0]->newInstance()->field;
        if ($field !== null) {
            return self::resolveField($param, $type, $typeName, $field, $data, 'Form field', $withConstraints);
        }

        if ($typeName === 'array') {
            return $data;
        }
        if ($typeName === 'object' || $typeName === stdClass::class) {
            return (object) $data;
        }
        if ($typeName !== null && class_exists($typeName)) {
            return self::hydrateFromArray($data, $typeName, '', $withConstraints);
        }
        throw new LogicException(sprintf(
            "Request form-data parameter '\$%s' has unsupported type '%s'",
            $param->getName(),
            $typeName,
        ));
    }

    private static function resolveRequestXml(
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
        bool $withConstraints,
    ): mixed {
        $raw  = $request->getRawBody() ?? '';
        $xml  = @simplexml_load_string($raw);
        $data = $xml ? (json_decode(json_encode($xml), true) ?: []) : [];

        $field = $param->getAttributes(RequestXml::class)[0]->newInstance()->field;
        if ($field !== null) {
            return self::resolveField($param, $type, $typeName, $field, $data, 'XML field', $withConstraints);
        }

        if ($typeName === 'array') {
            return $data;
        }
        if ($typeName === 'object' || $typeName === stdClass::class) {
            return $xml ? json_decode(json_encode($xml)) : new stdClass();
        }
        if ($typeName !== null && class_exists($typeName)) {
            if ($param->isVariadic()) {
                return self::resolveVariadicXml($data, $typeName, $withConstraints);
            }
            return self::hydrateFromArray($data, $typeName, '', $withConstraints);
        }
        throw new LogicException(sprintf(
            "Request XML body parameter '\$%s' has unsupported type '%s'",
            $param->getName(),
            $typeName,
        ));
    }

    private static function resolveRequestQuery(
        ReflectionParameter $param,
        ?string $typeName,
        HttpRequest $request,
        bool $withConstraints,
    ): mixed {
        $data = $request->getQueryParams();

        if ($typeName === 'array') {
            return $data;
        }
        if ($typeName === 'object' || $typeName === stdClass::class) {
            return (object) $data;
        }
        if ($typeName !== null && class_exists($typeName)) {
            return self::hydrateFromArray($data, $typeName, '', $withConstraints);
        }
        throw new LogicException(sprintf(
            "Request query string parameter '\$%s' has unsupported type '%s'",
            $param->getName(),
            $typeName,
        ));
    }

    private static function resolveRequestHeader(
        ReflectionAttribute $attr,
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
    ): mixed {
        $headerName = $attr->newInstance()->name
            ?? str_replace('_', '-', Tool::camelToKebab($param->getName()));
        $val = $request->getHeader($headerName);

        if ($val === null) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            if ($type?->allowsNull()) {
                return null;
            }
            RequestException::throw("Required header '$headerName' is missing");
        }

        return self::cast($val, $typeName, "Header '$headerName'");
    }

    private static function resolveDefault(
        ReflectionParameter $param,
        ?ReflectionNamedType $type,
        ?string $typeName,
        HttpRequest $request,
        HttpResponse $response,
        array $pathParams,
    ): mixed {
        if ($typeName !== null && is_a($typeName, HttpRequest::class, true)) {
            return $request;
        }
        if ($typeName !== null && is_a($typeName, HttpResponse::class, true)) {
            return $response;
        }
        if (array_key_exists($param->getName(), $pathParams)) {
            return self::required(
                $pathParams[$param->getName()],
                $param,
                $typeName,
                "Path variable '{$param->getName()}'"
            );
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        if ($type?->allowsNull()) {
            return null;
        }
        throw new RuntimeException(sprintf(
            "Cannot resolve parameter '\$%s' in %s::%s() — add an annotation or a default value",
            $param->getName(),
            $param->getDeclaringClass()?->getName(),
            $param->getDeclaringFunction()->getName(),
        ));
    }

    // ── Hydration ─────────────────────────────────────────────────────────────

    /**
     * Constructs any class from an associative array.
     * Collects all structural errors (missing/wrong-type fields) before throwing.
     * When $validate=true, also runs #[Constraint] checks on #[ListOf] elements
     * in one combined pass — so structural and constraint errors are reported together.
     *
     * @template T of object
     * @param class-string<T> $typeName
     * @return T
     * @throws ValidationException
     */
    private static function hydrateFromArray(
        array $data,
        string $typeName,
        string $prefix = '',
        bool $validate = false,
    ): object {
        $errors = [];
        $args   = [];

        foreach (ReflectionCache::parameters($typeName, '__construct') as $param) {
            $name      = $param->getName();
            $pType     = $param->getType() instanceof ReflectionNamedType ? $param->getType() : null;
            $pTypeName = $pType?->getName();
            $key       = $prefix !== '' ? "$prefix.$name" : $name;

            if (!array_key_exists($name, $data)) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } elseif ($pType?->allowsNull()) {
                    $args[] = null;
                } else {
                    $errors[$key][] = 'is required';
                }
                continue;
            }

            $val = $data[$name];

            if ($val === null) {
                $args[] = null;
                if (!$pType?->allowsNull()) {
                    $errors[$key][] = 'must not be null';
                }
                continue;
            }

            // Nested DTO
            if ($pTypeName !== null && class_exists($pTypeName) && !self::isCastableClass($pTypeName)) {
                if (!is_array($val)) {
                    $errors[$key][] = 'must be an object, got ' . get_debug_type($val);
                    $args[] = null;
                    continue;
                }
                try {
                    $args[] = self::hydrateFromArray($val, $pTypeName, $key, $validate);
                } catch (ValidationException $e) {
                    self::mergeErrors($errors, $e->getErrors());
                    $args[] = null;
                }
                continue;
            }

            // #[ListOf] typed collection — structural + constraint in one pass when $validate
            if ($pTypeName === 'array' && ($arrayAttr = $param->getAttributes(ListOf::class)[0] ?? null)) {
                if (!is_array($val)) {
                    $errors[$key][] = 'must be an array';
                    $args[] = [];
                    continue;
                }
                if (!array_is_list($val) && count($val) === 1) {
                    $inner = reset($val);
                    $val   = is_array($inner) && array_is_list($inner) ? $inner : [$inner];
                }
                $elementClass = $arrayAttr->newInstance()->class;
                $collection   = [];
                foreach ($val as $i => $item) {
                    if (!is_array($item)) {
                        $errors["{$key}[$i]"][] = 'must be an object';
                        continue;
                    }
                    try {
                        $obj = self::hydrateFromArray($item, $elementClass, "{$key}[$i]", $validate);
                        if ($validate) {
                            self::runValidation($obj, "{$key}[$i]");
                        }
                        $collection[] = $obj;
                    } catch (ValidationException $e) {
                        self::mergeErrors($errors, $e->getErrors());
                    }
                }
                if ($validate) {
                    foreach ($param->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                        $constraint = $attr->newInstance();
                        if ($msg = $constraint->validate($collection, $name)) {
                            $errors[$key][] = self::resolveMessage($msg, $name, $constraint);
                        }
                    }
                }
                $args[] = $collection;
                continue;
            }

            // Scalar / enum / datetime
            try {
                $castedVal = self::cast($val, $pTypeName, $name);
                $args[] = $castedVal;
                if ($validate) {
                    foreach ($param->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                        $constraint = $attr->newInstance();
                        if ($msg = $constraint->validate($castedVal, $name)) {
                            $errors[$key][] = self::resolveMessage($msg, $name, $constraint);
                        }
                    }
                }
            } catch (Throwable $e) {
                $errors[$key][] = $e->getMessage();
                $args[] = null;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new $typeName(...$args);
    }

    // ── Constraint validation ─────────────────────────────────────────────────

    /**
     * Runs #[Constraint] checks on all constructor params of the DTO.
     * Cascades into nested objects with #[Valid] on their field, and into
     * all #[ListOf] collections (implicit cascade).
     */
    private static function runValidation(object $value, string $prefix = ''): void
    {
        $errors = [];

        foreach (ReflectionCache::parameters($value::class, '__construct') as $param) {
            $name       = $param->getName();
            $fieldValue = $value->{$name};
            $key        = $prefix !== '' ? "$prefix.$name" : $name;

            if (is_object($fieldValue) && $param->getAttributes(Valid::class)) {
                try {
                    self::runValidation($fieldValue, $key);
                } catch (ValidationException $e) {
                    self::mergeErrors($errors, $e->getErrors());
                }
            }

            if (is_array($fieldValue) && ($param->getAttributes(ListOf::class)[0] ?? null)) {
                foreach ($fieldValue as $i => $item) {
                    if (is_object($item)) {
                        try {
                            self::runValidation($item, "{$key}[$i]");
                        } catch (ValidationException $e) {
                            self::mergeErrors($errors, $e->getErrors());
                        }
                    }
                }
            }

            foreach ($param->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $constraint = $attr->newInstance();
                if ($msg = $constraint->validate($fieldValue, $name)) {
                    $errors[$key][] = self::resolveMessage($msg, $name, $constraint);
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function validateScalar(ReflectionParameter $param, mixed $value): void
    {
        $errors = [];
        $name   = $param->getName();
        foreach ($param->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attr) {
            $constraint = $attr->newInstance();
            if ($msg = $constraint->validate($value, $name)) {
                $errors[$name][] = self::resolveMessage($msg, $name, $constraint);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Resolves an i18n placeholder in a constraint message.
     *
     * Spring-style: a message wrapped in '{...}' is treated as a translation
     * key and routed through Locale::t(), receiving :field plus all public
     * properties of the constraint as named placeholders.
     *
     * Plain strings (no surrounding braces) are returned as-is.
     */
    private static function resolveMessage(string $message, string $field, Constraint $constraint): string
    {
        if (preg_match('/^\{(.+)\}$/', $message, $m) !== 1) {
            return $message;
        }
        $params = ['field' => $field] + get_object_vars($constraint);
        return Locale::t($m[1], $params);
    }

    // ── Scalar casting ────────────────────────────────────────────────────────

    private static function cast(mixed $value, ?string $typeName, string $label = 'Parameter'): mixed
    {
        // An array reaching a scalar is a client error worth reporting — `?id[]=1` bound
        // to an int would otherwise cast to the string "Array". But the check has to name
        // the types that genuinely reject an array: `mixed` and `iterable` accept one, and
        // comparing against 'array' alone refused them. A DataGrid filter is where this
        // surfaced — its `value` is `mixed` because `isAnyOf` sends a list where
        // `contains` sends a string.
        if (is_array($value) && $typeName !== null && !in_array($typeName, self::ARRAY_COMPATIBLE, true)) {
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
            $typeName !== null && enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class)
                => self::castEnum($value, $typeName, $label),
            $typeName === DateTimeImmutable::class => self::castDateTimeImmutable($value, $label),
            $typeName === DateTime::class          => self::castDateTime($value, $label),
            $typeName === 'BcMath\Number' && extension_loaded('bcmath')    => self::castBcMathNumber($value, $label),
            $typeName === 'Decimal\Decimal' && extension_loaded('decimal') => self::castDecimal($value, $label),
            default => $value,
        };
    }

    /** @param class-string<BackedEnum> $typeName */
    private static function castEnum(mixed $value, string $typeName, string $label): BackedEnum
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
        } catch (ValueError) {
            $valid = implode(', ', array_column($typeName::cases(), 'value'));
            RequestException::throw("$label must be one of [$valid], got '$value'");
        }
    }

    private static function castDateTimeImmutable(mixed $value, string $label): DateTimeImmutable
    {
        $str = (string) $value;
        if ($str === '') {
            RequestException::throw(
                "$label has invalid date '' — expected "
                . "ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)"
            );
        }
        try {
            return new DateTimeImmutable($str);
        } catch (Exception) {
            RequestException::throw(
                "$label has invalid date '$value' — expected "
                . "ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)"
            );
        }
    }

    private static function castDateTime(mixed $value, string $label): DateTime
    {
        $str = (string) $value;
        if ($str === '') {
            RequestException::throw(
                "$label has invalid date '' — expected "
                . "ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)"
            );
        }
        try {
            return new DateTime($str);
        } catch (Exception) {
            RequestException::throw(
                "$label has invalid date '$value' — expected "
                . "ISO 8601 (e.g. 2024-01-31 or 2024-01-31T12:00:00)"
            );
        }
    }

    private static function castBcMathNumber(mixed $value, string $label): BcNumber
    {
        if (!is_numeric($value)) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
        try {
            return new BcNumber((string) $value);
        } catch (ValueError) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
    }

    private static function castDecimal(mixed $value, string $label): Decimal
    {
        if (!is_numeric($value)) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
        try {
            return method_exists(Decimal::class, 'valueOf')
                ? Decimal::valueOf((string) $value)
                : new Decimal((string) $value);
        } catch (Throwable) {
            RequestException::throw("$label must be a numeric value (int or decimal string), got '$value'");
        }
    }

    // ── Body parsing helpers ──────────────────────────────────────────────────

    /**
     * Body of an auto-detected request as an array.
     *
     * A form is read from {@see HttpRequest::getParsedBody()} rather than from the raw
     * bytes: `multipart/form-data` cannot be parsed back out of them at all — PHP has
     * already consumed the stream into the parsed body — and doing it by hand for
     * `x-www-form-urlencoded` alone would make the two form encodings behave
     * differently. Without this branch a form fell through to `json_decode()` and became
     * an empty array, so every DTO field came back "is required" with nothing naming the
     * cause.
     */
    private static function parseBodyAsArray(string $ct, string $raw, HttpRequest $request): array
    {
        if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
            $xml = @simplexml_load_string($raw);
            return $xml ? (json_decode(json_encode($xml), true) ?: []) : [];
        }
        if (self::isFormContentType($ct)) {
            return $request->getParsedBody();
        }
        return json_decode($raw, true) ?? [];
    }

    private static function parseBodyAsObject(string $ct, string $raw, HttpRequest $request): stdClass
    {
        if (str_contains($ct, 'application/xml') || str_contains($ct, 'text/xml')) {
            $xml = @simplexml_load_string($raw);
            return $xml ? json_decode(json_encode($xml)) : new stdClass();
        }
        if (self::isFormContentType($ct)) {
            return (object) $request->getParsedBody();
        }
        return json_decode($raw) ?? new stdClass();
    }

    /** Both form encodings PHP populates the parsed body from. */
    private static function isFormContentType(string $ct): bool
    {
        return str_contains($ct, 'application/x-www-form-urlencoded')
            || str_contains($ct, 'multipart/form-data');
    }

    // ── Misc helpers ──────────────────────────────────────────────────────────

    private static function isCastableClass(string $typeName): bool
    {
        return (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class))
            || $typeName === DateTimeImmutable::class
            || $typeName === DateTime::class
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

    private static function mergeErrors(array &$errors, array $new): void
    {
        foreach ($new as $k => $msgs) {
            $errors[$k] = isset($errors[$k]) ? array_merge($errors[$k], $msgs) : $msgs;
        }
    }

    private static function validateFile(array $file, string $fieldName, RequestFile $annotation): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            RequestException::throw("Uploaded file '$fieldName' transfer error (code $error)");
        }
        if ($annotation->maxSize !== null) {
            if (($file['size'] ?? 0) > self::parseSize($annotation->maxSize)) {
                RequestException::throw(
                    "Uploaded file '$fieldName' exceeds maximum size of $annotation->maxSize"
                );
            }
        }
        if ($annotation->accept !== []) {
            $mime = new finfo(FILEINFO_MIME_TYPE)->file($file['tmp_name'] ?? '');
            if (!self::mimeAccepted($mime ?: '', $file['name'] ?? '', $annotation->accept)) {
                $allowed = implode(', ', $annotation->accept);
                RequestException::throw(
                    "Uploaded file '$fieldName' type '$mime' is not allowed (accepted: $allowed)"
                );
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
                if ('.' . strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === strtolower($pattern)) {
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
