<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\Http\Request;

use DateTime;

/**
 * String-rule validation helpers extracted from the deprecated RequestObject.
 * Kept for backwards compatibility — new code should use #[Constraint] attributes.
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
     * @param array<callable|string> $rules
     */
    final protected function validate(
        string $field,
        array $rules,
        bool $required = true,
        ?string $message = null,
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
