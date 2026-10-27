<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\App;

/**
 * Where the listening components bind, as `call run` was told at launch: one flag pair
 * per component, named after it — `--http.port=9000`, `--chat.port=9101 --chat.host=…`.
 * The name is the one `call application info` shows, in any letter case.
 *
 * What a component was declared with is the default; a flag replaces it. For the HTTP
 * server a {@see \Flytachi\Winter\Kernel\App\Config\WebConfigurer} still has the last
 * word — code that sets the address on purpose.
 *
 * A bare `--port` / `--host` is refused, and so is a flag naming no listening component:
 * with an HTTP server and a WebSocket, a bare port names neither, and a flag that is
 * quietly ignored leaves the application on an address nobody asked for.
 *
 * @internal Read by {@see \Flytachi\Winter\Kernel\WinterApplication} at launch.
 */
final class ListenFlags
{
    /** Static-only — not instantiable. */
    private function __construct()
    {
    }

    /**
     * The components with the flags applied.
     *
     * @param list<Component> $components
     * @return list<Component>
     * @throws ApplicationConfigException A bare, unknown or malformed address flag.
     */
    public static function apply(array $components, ApplicationArguments $args): array
    {
        $given = [];
        foreach ($args->options() as $key => $value) {
            $flag = self::parse($key);
            if ($flag === null) {
                continue;
            }
            [$name, $field] = $flag;
            if ($name === '') {
                throw new ApplicationConfigException(
                    "--{$key} does not say whose {$field} it is: name the component. " . self::hint($components, $field)
                );
            }
            $index = self::listener($components, $name, $field, $key);
            if (!is_string($value) || trim($value) === '') {
                throw new ApplicationConfigException("--{$key} needs a value: --{$key}=…");
            }
            if ($field === 'port') {
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
                    throw new ApplicationConfigException("--{$key} must be a port, 1–65535: {$value} given.");
                }
                $given[$index]['port'] = (int) $value;
            } else {
                $given[$index]['host'] = trim($value);
            }
        }

        foreach ($given as $index => $address) {
            $component = $components[$index];
            $components[$index] = $component->at(
                $address['host'] ?? $component->host,
                $address['port'] ?? $component->port,
            );
        }
        return $components;
    }

    /**
     * Whether the launch named this component's port or host.
     *
     * @param 'port'|'host' $field
     */
    public static function given(ApplicationArguments $args, Component $component, string $field): bool
    {
        foreach (array_keys($args->options()) as $key) {
            if (self::parse($key) === [strtolower($component->name()), $field]) {
                return true;
            }
        }
        return false;
    }

    /**
     * The component a flag names and the field it sets; null for an option that is not an
     * address flag. A bare `--port` / `--host` names an empty component.
     *
     * @return array{string, string}|null
     */
    private static function parse(string $key): ?array
    {
        $lower = strtolower($key);
        if ($lower === 'port' || $lower === 'host') {
            return ['', $lower];
        }
        if (!preg_match('/^(.+)\.(port|host)$/', $lower, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    /**
     * The index of the one listening component the flag names.
     *
     * @param list<Component> $components
     * @throws ApplicationConfigException No such component, one that does not listen, or two of that name.
     */
    private static function listener(array $components, string $name, string $field, string $key): int
    {
        $found = [];
        $other = null;
        foreach ($components as $index => $component) {
            if (strtolower($component->name()) !== $name) {
                continue;
            }
            if ($component->listens()) {
                $found[] = $index;
            } else {
                $other = $component;
            }
        }
        if (count($found) === 1) {
            return $found[0];
        }
        if (count($found) > 1) {
            throw new ApplicationConfigException(
                "--{$key} is ambiguous: two listening components are named {$name}."
            );
        }
        if ($other !== null) {
            throw new ApplicationConfigException("--{$key}: {$other->name()} does not listen on a port.");
        }
        throw new ApplicationConfigException(
            "--{$key}: no listening component is named {$name}. " . self::hint($components, $field)
        );
    }

    /**
     * The flags this application takes, for an error message.
     *
     * @param list<Component> $components
     */
    private static function hint(array $components, string $field): string
    {
        $flags = [];
        foreach ($components as $component) {
            if ($component->listens()) {
                $flags[] = '--' . strtolower($component->name()) . '.' . $field;
            }
        }
        return $flags === []
            ? 'This application has none.'
            : 'This application has: ' . implode(', ', $flags) . '.';
    }
}
