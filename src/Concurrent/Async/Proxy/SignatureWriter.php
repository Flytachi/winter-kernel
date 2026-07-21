<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Concurrent\Async\Proxy;

use Flytachi\Winter\K2\Concurrent\Async\AsyncException;

/**
 * Renders reflected method signatures back into PHP source.
 *
 * A generated proxy overrides the original method, so its declaration has to be
 * accepted by the engine as compatible — which makes this the least forgiving
 * part of the whole layer. Every shape the type system allows must survive the
 * round trip: nullable and union and intersection types, DNF combinations,
 * variadics, defaults referring to constants, enum cases.
 *
 * Two names need care because their meaning is relative to where they are
 * written: `self` and `parent` are resolved against the *declaring* class, or
 * the override would silently mean something else. `static` stays as-is — it is
 * covariant and keeps working in a subclass.
 *
 * @internal
 */
final class SignatureWriter
{
    private function __construct()
    {
    }

    /**
     * Renders a full parameter list, ready to be placed between parentheses.
     *
     * @param \ReflectionMethod $method Method being overridden.
     * @return string Rendered parameters, comma separated.
     */
    public static function parameters(\ReflectionMethod $method): string
    {
        $rendered = [];
        foreach ($method->getParameters() as $parameter) {
            $rendered[] = self::parameter($parameter, $method);
        }

        return implode(', ', $rendered);
    }

    /**
     * Renders the argument list forwarding the call to the parent method.
     *
     * @param \ReflectionMethod $method Method being overridden.
     * @return string Rendered arguments, comma separated.
     */
    public static function arguments(\ReflectionMethod $method): string
    {
        $rendered = [];
        foreach ($method->getParameters() as $parameter) {
            $rendered[] = ($parameter->isVariadic() ? '...$' : '$') . $parameter->getName();
        }

        return implode(', ', $rendered);
    }

    /**
     * Renders a type as it must appear in the overriding declaration.
     *
     * @param \ReflectionType|null $type Type to render, or null when untyped.
     * @param \ReflectionClass $scope Class the original declaration lives in.
     * @return string Rendered type, or an empty string when there is none.
     */
    public static function type(?\ReflectionType $type, \ReflectionClass $scope): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof \ReflectionUnionType) {
            $members = [];
            foreach ($type->getTypes() as $member) {
                $members[] = $member instanceof \ReflectionIntersectionType
                    ? '(' . self::intersection($member, $scope) . ')'
                    : self::named($member, $scope, false);
            }

            return implode('|', $members);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return self::intersection($type, $scope);
        }

        if ($type instanceof \ReflectionNamedType) {
            return self::named($type, $scope, true);
        }

        throw AsyncException::of(
            $scope->getName(),
            'type ' . $type . ' is not supported',
            'Use a named, union or intersection type.'
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param \ReflectionParameter $parameter Parameter to render.
     * @param \ReflectionMethod $method Method the parameter belongs to.
     */
    private static function parameter(\ReflectionParameter $parameter, \ReflectionMethod $method): string
    {
        $out = self::type($parameter->getType(), $method->getDeclaringClass());
        if ($out !== '') {
            $out .= ' ';
        }

        if ($parameter->isPassedByReference()) {
            throw AsyncException::of(
                $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()',
                'parameter $' . $parameter->getName() . ' is passed by reference',
                'An asynchronous call returns before the body runs, so writes to it could never be observed. '
                . 'Return the value instead.'
            );
        }

        if ($parameter->isVariadic()) {
            return $out . '...$' . $parameter->getName();
        }

        $out .= '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $out .= ' = ' . self::defaultValue($parameter, $method);
        }

        return $out;
    }

    /**
     * @param \ReflectionNamedType $type Type to render.
     * @param \ReflectionClass $scope Class the original declaration lives in.
     * @param bool $shorthand Whether the `?T` shorthand may be used.
     */
    private static function named(\ReflectionNamedType $type, \ReflectionClass $scope, bool $shorthand): string
    {
        $name = $type->getName();
        $lowered = strtolower($name);

        $resolved = match ($lowered) {
            // Relative to where it is written, so it must be spelled out.
            'self' => '\\' . $scope->getName(),
            'parent' => '\\' . self::parentOf($scope)->getName(),
            default => $type->isBuiltin() ? $lowered : '\\' . $name,
        };

        if ($shorthand && $type->allowsNull() && $lowered !== 'mixed' && $lowered !== 'null') {
            return '?' . $resolved;
        }

        return $resolved;
    }

    /**
     * @param \ReflectionIntersectionType $type Type to render.
     * @param \ReflectionClass $scope Class the original declaration lives in.
     */
    private static function intersection(\ReflectionIntersectionType $type, \ReflectionClass $scope): string
    {
        $members = [];
        foreach ($type->getTypes() as $member) {
            $members[] = $member instanceof \ReflectionNamedType
                ? self::named($member, $scope, false)
                : self::type($member, $scope);
        }

        return implode('&', $members);
    }

    /**
     * @param \ReflectionClass $scope Class whose parent is needed.
     */
    private static function parentOf(\ReflectionClass $scope): \ReflectionClass
    {
        $parent = $scope->getParentClass();
        if ($parent === false) {
            throw AsyncException::of(
                $scope->getName(),
                'the signature refers to "parent" but the class has none',
                'Name the type explicitly.'
            );
        }

        return $parent;
    }

    /**
     * Renders a parameter default.
     *
     * PHP only requires an overriding parameter to *stay* optional — the value
     * itself is never compared — but reproducing it faithfully keeps reflection
     * and IDE hints on the proxy truthful.
     *
     * @param \ReflectionParameter $parameter Parameter carrying the default.
     * @param \ReflectionMethod $method Method the parameter belongs to.
     */
    private static function defaultValue(\ReflectionParameter $parameter, \ReflectionMethod $method): string
    {
        if ($parameter->isDefaultValueConstant()) {
            $reference = self::constantReference(
                (string) $parameter->getDefaultValueConstantName(),
                $method->getDeclaringClass()
            );

            // A private constant is invisible from the subclass, so the literal
            // value is inlined instead of the reference.
            if ($reference !== null) {
                return $reference;
            }
        }

        return self::export(
            $parameter->getDefaultValue(),
            $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()',
            '$' . $parameter->getName()
        );
    }

    /**
     * Resolves a constant default into source, or null when it is unreachable
     * from the generated subclass.
     *
     * @param string $name Constant name as reported by reflection.
     * @param \ReflectionClass $scope Class the original declaration lives in.
     */
    private static function constantReference(string $name, \ReflectionClass $scope): ?string
    {
        if (!str_contains($name, '::')) {
            return '\\' . ltrim($name, '\\');
        }

        [$owner, $constant] = explode('::', $name, 2);
        $owner = match (strtolower($owner)) {
            'self', 'static' => $scope->getName(),
            'parent' => self::parentOf($scope)->getName(),
            default => ltrim($owner, '\\'),
        };

        try {
            if ((new \ReflectionClassConstant($owner, $constant))->isPrivate()) {
                return null;
            }
        } catch (\ReflectionException) {
            return null;
        }

        return '\\' . $owner . '::' . $constant;
    }

    /**
     * Exports a runtime value as a PHP literal.
     *
     * @param mixed $value Value to export.
     * @param string $subject Method the value belongs to, used for error messages.
     * @param string $where Parameter name, used for error messages.
     */
    private static function export(mixed $value, string $subject, string $where): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value instanceof \UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        if (is_array($value)) {
            $parts = [];
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                $parts[] = ($list ? '' : self::export($key, $subject, $where) . ' => ')
                    . self::export($item, $subject, $where);
            }

            return '[' . implode(', ', $parts) . ']';
        }

        if (is_object($value)) {
            throw AsyncException::of(
                $subject,
                'default value of ' . $where . ' is an object of ' . $value::class
                . ', which cannot be written back as source',
                'Default to null and build the object inside the method.'
            );
        }

        return var_export($value, true);
    }
}
