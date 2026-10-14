<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Cache;

/**
 * Turns a key template into the PHP expression a proxy evaluates.
 *
 * `'product:{id}'` becomes `'product:' . CacheSupport::part($id)`: the template is
 * read once, when the proxy is generated, so a cached call never parses it, and a
 * placeholder naming a parameter that does not exist stops the build instead of
 * producing a key that matches nothing.
 *
 * Placeholders are parameter names, optionally followed by public properties —
 * `{filter.region}` — and `{result}` for the returned value where the attribute
 * runs after the body.
 *
 * @internal
 */
final class KeyCompiler
{
    /** Placeholder for the value the method returned. */
    public const string RESULT = 'result';

    /** Local variable the proxy keeps the returned value in. */
    public const string RESULT_VARIABLE = '$__winterResult';

    private const string SUPPORT = '\\Flytachi\\Winter\\Kernel\\Cache\\CacheSupport';
    private const string PATH = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** Builtin types with a stable spelling in a key. */
    private const array KEYABLE_BUILTINS = [
        'int', 'float', 'string', 'bool', 'true', 'false', 'null', 'array', 'mixed',
    ];

    private function __construct()
    {
    }

    /**
     * Compiles the key of one attribute.
     *
     * @param string|null $template Key template; null builds the key from every argument.
     * @param \ReflectionMethod $method Method the attribute is on.
     * @param string $subject Method name used in error messages.
     * @param string $attribute Attribute name used in error messages, e.g. `#[Cacheable]`.
     * @param bool $resultAllowed Whether `{result}` is available — after the body, on a method that returns.
     * @return string PHP expression evaluating to the key.
     * @throws CacheException If the template or a parameter cannot make a key.
     */
    public static function compile(
        ?string $template,
        \ReflectionMethod $method,
        string $subject,
        string $attribute,
        bool $resultAllowed,
    ): string {
        if ($template === null) {
            return self::fromArguments($method, $subject, $attribute);
        }

        if ($template === '') {
            throw CacheException::of(
                $subject,
                $attribute . ' has an empty key',
                'Omit key to build it from the arguments, or give it a template such as \'{id}\'.'
            );
        }

        $pieces = preg_split('/(\{[^{}]*\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $parts = [];

        foreach ($pieces ?: [] as $piece) {
            if ($piece[0] === '{' && str_ends_with($piece, '}')) {
                $parts[] = self::placeholder(substr($piece, 1, -1), $method, $subject, $attribute, $resultAllowed);
                continue;
            }

            if (str_contains($piece, '{') || str_contains($piece, '}')) {
                throw CacheException::of(
                    $subject,
                    $attribute . " key '{$template}' has an unbalanced brace",
                    'Write placeholders as {name}.'
                );
            }

            $parts[] = var_export($piece, true);
        }

        return implode(' . ', $parts);
    }

    /**
     * Whether a declared type always has a stable spelling in a key.
     *
     * An untyped or `mixed` value is let through and checked when the key is built.
     *
     * @param \ReflectionType|null $type Declared type.
     * @param \ReflectionClass $scope Class the declaration lives in, for `self`.
     */
    public static function isKeyable(?\ReflectionType $type, \ReflectionClass $scope): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (!self::isKeyable($member, $scope)) {
                    return false;
                }
            }

            return true;
        }

        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }

        $name = strtolower($type->getName());
        if ($type->isBuiltin()) {
            return in_array($name, self::KEYABLE_BUILTINS, true);
        }

        $class = in_array($name, ['self', 'static'], true) ? $scope->getName() : $type->getName();
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            return false;
        }

        $ref = new \ReflectionClass($class);

        return $ref->isEnum() || $ref->implementsInterface(\Stringable::class);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function fromArguments(\ReflectionMethod $method, string $subject, string $attribute): string
    {
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            if (!self::isKeyable($parameter->getType(), $method->getDeclaringClass())) {
                throw CacheException::of(
                    $subject,
                    $attribute . ' has no key, and parameter $' . $parameter->getName() . ' of type '
                    . $parameter->getType() . ' cannot be part of one',
                    'Give the attribute a key naming what identifies the entry, e.g. key: \'{'
                    . $parameter->getName() . '.id}\'.'
                );
            }
            $arguments[] = '$' . $parameter->getName();
        }

        return $arguments === []
            ? "''"
            : self::SUPPORT . '::key(' . implode(', ', $arguments) . ')';
    }

    private static function placeholder(
        string $path,
        \ReflectionMethod $method,
        string $subject,
        string $attribute,
        bool $resultAllowed,
    ): string {
        if (preg_match(self::PATH, $path) !== 1) {
            throw CacheException::of(
                $subject,
                $attribute . " key placeholder '{{$path}}' is not a parameter name",
                'Use {parameter} or {parameter.property}.'
            );
        }

        $segments = explode('.', $path);
        $root = array_shift($segments);
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        if ($root === self::RESULT && $resultAllowed) {
            if (isset($parameters[self::RESULT])) {
                throw CacheException::of(
                    $subject,
                    $attribute . ' key uses {result}, which is ambiguous here: the method also has a parameter $result',
                    'Rename the parameter.'
                );
            }
            $expression = self::RESULT_VARIABLE;
            $type = $method->getReturnType();
        } elseif (isset($parameters[$root])) {
            $expression = '$' . $root;
            $type = $parameters[$root]->getType();
        } else {
            $known = $parameters === [] ? 'it has none' : 'it has $' . implode(', $', array_keys($parameters));
            $hint = $root === self::RESULT
                ? '{result} is available only to #[CachePut] and to #[CacheEvict] after the body of a method '
                    . 'that returns a value.'
                : 'Name one of its parameters.';

            throw CacheException::of(
                $subject,
                $attribute . " key refers to {{$root}}, but the method has no parameter \${$root} ({$known})",
                $hint
            );
        }

        foreach ($segments as $property) {
            [$expression, $type] = self::property($expression, $type, $property, $method, $subject, $attribute, $path);
        }

        if (!self::isKeyable($type, $method->getDeclaringClass())) {
            throw CacheException::of(
                $subject,
                $attribute . " key placeholder {{$path}} is of type {$type}, which cannot be part of a key",
                'Point it at a scalar, an enum or a Stringable, e.g. {' . $path . '.id}.'
            );
        }

        return self::SUPPORT . '::part(' . $expression . ')';
    }

    /**
     * @return array{string, \ReflectionType|null} Expression reaching the property, and its type.
     */
    private static function property(
        string $expression,
        ?\ReflectionType $type,
        string $property,
        \ReflectionMethod $method,
        string $subject,
        string $attribute,
        string $path,
    ): array {
        $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
        if ($class !== null && in_array(strtolower($class), ['self', 'static'], true)) {
            $class = $method->getDeclaringClass()->getName();
        }

        if ($class === null || !class_exists($class)) {
            throw CacheException::of(
                $subject,
                $attribute . " key placeholder {{$path}} reads ->{$property} from a value not declared as a class"
                . ($type === null ? '' : " ({$type})"),
                'Declare the parameter with its class type so the property can be checked.'
            );
        }

        $ref = new \ReflectionClass($class);
        $reflected = $ref->hasProperty($property) ? $ref->getProperty($property) : null;
        if ($reflected === null || !$reflected->isPublic() || $reflected->isStatic()) {
            throw CacheException::of(
                $subject,
                $attribute . " key placeholder {{$path}}: {$class} has no public property \${$property}",
                'Use a public property, or pass the value as its own parameter.'
            );
        }

        // Null-safe, so a missing object spells as null instead of failing the call.
        return [$expression . '?->' . $property, $reflected->getType()];
    }
}
