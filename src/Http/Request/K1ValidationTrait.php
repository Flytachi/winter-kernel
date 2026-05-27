<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request;

use DateTime;
use Flytachi\Winter\K2\Localization\Locale;

/**
 * Legacy string-rule validation helpers extracted from RequestObject.
 *
 * Kept for backwards compatibility — new code should prefer the attribute-based
 * system (#[Valid] + #[Constraint] under Flytachi\Winter\K2\Http\Request\Validation\*),
 * which collects all errors at once and integrates with i18n natively.
 *
 * This trait still supports the same {key} translation-key syntax in the optional
 * `$message` parameter as the attribute system: a message wrapped in '{...}' is
 * resolved through Locale::t() with `:field` available as a named placeholder.
 */
trait K1ValidationTrait
{
    /**
     * Validate a field using rule strings or callables.
     *
     * Rules: 'boolean', 'numeric', 'string', 'array',
     *        'length:min,max', 'range:min,max', 'in:a,b,c',
     *        'email', 'url', 'uuid', 'ip', 'ipv4', 'ipv6',
     *        'msisdn', 'phone', 'datetime[:format]', 'positive', 'negative'
     *
     * The `*` wildcard expands over every element of the array/object at that
     * position, e.g. `staffs.*.id` validates the `id` of each item in `staffs`.
     * Rules apply per existing element; if the parent collection is missing or
     * empty, no element-level checks run (validate the parent itself separately).
     *
     * @param array<callable|string> $rules
     * @param string|null $message Custom error text for any rule failure on this field.
     *                             If wrapped in '{...}' it is resolved through Locale::t()
     *                             with `:field` available as a named placeholder.
     */
    final protected function validate(
        string $field,
        array $rules,
        bool $required = true,
        ?string $message = null,
    ): static {
        if (str_contains($field, '*')) {
            foreach ($this->expandWildcard($field) as $resolvedField) {
                $this->validateField($resolvedField, $rules, $required, $message);
            }
            return $this;
        }

        return $this->validateField($field, $rules, $required, $message);
    }

    /**
     * Validate a single, fully-resolved field path (no wildcards).
     *
     * @param array<callable|string> $rules
     */
    private function validateField(
        string $field,
        array $rules,
        bool $required,
        ?string $message,
    ): static {
        $value = $this->get($field);

        if (!$required && $value === null) {
            return $this;
        }

        if ($required && $value === null && !property_exists($this, $field)) {
            $this->fail($field, $message ?? "Required field '{$field}' not found", $message);
        }

        foreach ($rules as $rule) {
            if (is_callable($rule)) {
                if (!$rule($value)) {
                    $this->fail(
                        $field,
                        $message ?? "Field '{$field}' failed custom validation",
                        $message
                    );
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

    /**
     * Throws RequestException with the given (already-defaulted) message,
     * resolving {key} translation markers through Locale::t() when present.
     *
     * @param string $field The field name, exposed as :field in translations.
     * @param string $resolvedMessage The message to throw — either the user override
     *                                or the rule's default text.
     * @param string|null $userMessage Original `$message` argument from validate(),
     *                                 used to detect '{key}' translation markers.
     */
    private function fail(string $field, string $resolvedMessage, ?string $userMessage): void
    {
        if ($userMessage !== null && preg_match('/^\{(.+)\}$/', $userMessage, $m) === 1) {
            $resolvedMessage = Locale::t($m[1], ['field' => $field]);
        }
        RequestException::throw($resolvedMessage);
    }

    private function get(string $field): mixed
    {
        return $this->getByParts(explode('.', $field));
    }

    /**
     * Traverse the request graph following an already-split path.
     *
     * @param list<string> $parts An empty list resolves to the request itself.
     */
    private function getByParts(array $parts): mixed
    {
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

    /**
     * Expand a path containing `*` wildcards into the concrete paths that exist
     * in the request graph, e.g. `staffs.*.id` → `staffs.0.id`, `staffs.1.id`.
     *
     * Each `*` fans out over the keys of the array/object at that position;
     * branches where the parent is missing or not iterable are dropped, so a
     * missing or empty collection yields zero paths.
     *
     * @return list<string> Concrete, wildcard-free field paths.
     */
    private function expandWildcard(string $field): array
    {
        /** @var list<list<string>> $frontier */
        $frontier = [[]];

        foreach (explode('.', $field) as $segment) {
            $next = [];
            foreach ($frontier as $prefix) {
                if ($segment !== '*') {
                    $next[] = [...$prefix, $segment];
                    continue;
                }

                $target = $this->getByParts($prefix);
                $keys   = match (true) {
                    is_array($target)  => array_keys($target),
                    is_object($target) => array_keys(get_object_vars($target)),
                    default            => [],
                };
                foreach ($keys as $key) {
                    $next[] = [...$prefix, (string) $key];
                }
            }
            $frontier = $next;
        }

        return array_map(static fn(array $parts) => implode('.', $parts), $frontier);
    }

    private function applyRule(string $field, mixed $value, string $rule, array $params, ?string $msg): void
    {
        $fail = fn(string $m) => $this->fail($field, $msg ?? $m, $msg);

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
            'ipv4', 'ip4'              => (is_string($value)
                                        && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
                                              || $fail("Field '{$field}' must be a valid IPv4"),
            'ipv6', 'ip6'              => (is_string($value)
                                        && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
                                              || $fail("Field '{$field}' must be a valid IPv6"),
            'uuid'                     => (is_string($value) && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            )) || $fail("Field '{$field}' must be a valid UUID"),
            'msisdn'                   => (is_string($value) && preg_match('/^\+[1-9]\d{6,14}$/', $value))
                                              || $fail("Field '{$field}' must be a valid MSISDN (E.164)"),
            'phone'                    => (is_string($value)
                                            && preg_match('/^\+?\d[\d\s\-\(\)]{5,20}$/', $value))
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
