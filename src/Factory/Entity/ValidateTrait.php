<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Entity;

use DateTime;

trait ValidateTrait
{
    /**
     * Runs validation for a single property of the object.
     *
     * This method serves as the main entry point for all validation checks.
     * It sequentially applies each rule from the `$rules` array to the given property (`$field`).
     * Validation stops at the first rule that fails.
     *
     *  Examples:
     *  ```
     *  // Validate that 'age' is numeric, and range 18 and 99
     *  $this->validate('age', ['numeric', 'range:18,99']);
     *
     *  // Validate that 'name' is a string up to 500 characters long
     *  $this->validate('name', ['string', 'length:0,500']);
     *
     *  // Validate that 'status' is one of the allowed values
     *  $this->validate('status', ['in:active,pending,archived']);
     *
     *  // Validate a nested field
     *  $this->validate('lang.ru.title', ['string', 'length:1,100']);
     *
     *  // Validate all 'title' fields within the 'lang' array
     *  $this->validate('lang.*.title', ['string', 'length:1,100']);
     *
     *  // Use a custom callback for complex validation logic
     *  $this->validate('payload', [function($value) {
     *      return is_array($value) && isset($value['id']);
     *  }]);
     *  ```
     *
     * @param string $field The name of the object's property to validate.
     * @param array<callable|callable-string|string> $rules An array of validation rules.
     *        - String-based rules: 'boolean', 'numeric', 'array', 'string', 'range:1,100'.
     *        - Custom rules: anonymous functions that accept the property value
     *          and return `true` (if validation passes) or `false`.
     * @param bool $required If false, validation is skipped for null or non-existent fields.
     *
     * @return static Returns `$this` to allow method chaining.
     */
    final protected function validate(string $field, array $rules, bool $required = true): static
    {
        if (!$required && (!property_exists($this, $field) || $this->$field === null)) {
            return $this;
        }

        if (str_contains($field, '*')) {
            $values = $this->validateDataGet($this, $field);

            if (is_array($values)) {
                foreach ($values as $index => $value) {
                    $specificFieldKey = str_replace('*', (string)$index, $field);
                    $this->applyRules($specificFieldKey, $value, $rules);
                }
            }
            return $this;
        }

        $value = $this->validateDataGet($this, $field);

        if (!$required && ($value === null)) {
            return $this;
        }

        $this->applyRules($field, $value, $rules);

        return $this;
    }

    /**
     * Helper method to apply a set of rules to a given value.
     *
     * @param string $field The display name of the field for error messages.
     * @param mixed $value The actual value to validate.
     * @param array $rules The rules to apply.
     */
    private function applyRules(string $field, mixed $value, array $rules): void
    {
        foreach ($rules as $rule) {
            if (is_callable($rule)) {
                if (!$rule($value)) {
                    RequestException::throw("Field '{$field}' failed validation check.");
                }
                continue;
            }

            $ruleName = $rule;
            $parameters = [];
            if (str_contains($rule, ':')) {
                [$ruleName, $paramString] = explode(':', $rule, 2);
                $parameters = explode(',', $paramString);
            }

            $naming = ucfirst(dashAsciiToCamelCase($ruleName));
            $methodName = 'validate' . match ($naming) {
                    'Bool'              => 'Boolean',
                    'Num', 'Number'     => 'Numeric',
                    'Arr', 'List'       => 'Array',
                    'Str', 'Text'       => 'String',

                    'Len', 'Ln'         => 'Length',
                    'Ran', 'Rg'         => 'Range',
                    'Positive', 'Id'    => 'NumberPositive',
                    'Negative'          => 'NumberNegative',

                    'Ip4'               => 'Ipv4',
                    'Ip6'               => 'Ipv6',

                    'Date', 'Time'      => 'Datetime',
                    default => $naming,
            };

            if (!method_exists($this, $methodName)) {
                RequestException::throw("Unknown validation rule '{$ruleName}'.");
            }

            $this->{$methodName}($field, $value, ...$parameters);
        }
    }

    /**
     * Safely retrieves a value from a nested array or object using "dot" notation.
     * Supports wildcard '*' to retrieve all items from a numeric or associative array.
     *
     * @param array|object $target The data structure to search within.
     * @param string $key The dot-separated path (e.g., 'user.address.city', 'posts.*.title').
     * @param mixed $default A default value to return if the key is not found.
     * @return mixed The found value or the default.
     */
    private function validateDataGet(array|object $target, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $target;
        }

        $segments = explode('.', $key);
        $currentSegment = array_shift($segments);
        $remainingKey = implode('.', $segments);

        if ($currentSegment === '*') {
            if (!is_array($target)) {
                return $default;
            }

            $result = [];
            foreach ($target as $key => $item) {
                if (!is_array($item) && !is_object($item)) {
                    $result[$key] = ($remainingKey === '') ? $item : $default;
                } else {
                    $result[$key] = $this->validateDataGet($item, $remainingKey, $default);
                }
            }
            return $result;
        }

        if (is_array($target) && array_key_exists($currentSegment, $target)) {
            $nextTarget = $target[$currentSegment];
        } elseif (is_object($target) && property_exists($target, $currentSegment)) {
            $nextTarget = $target->{$currentSegment};
        } else {
            return $default;
        }

        // 🛡 добавляем защиту перед рекурсией
        if (!is_array($nextTarget) && !is_object($nextTarget)) {
            return ($remainingKey === '') ? $nextTarget : $default;
        }

        return $this->validateDataGet($nextTarget, $remainingKey, $default);
    }


    // --- A set of built-in validation rules ---


    /**
     * Rule: 'boolean'
     * Checks whether a property's value is a boolean using is_bool().
     *
     * @param string $field The display name of the field.
     * @param mixed $value The value to validate.
     */
    private function validateBoolean(string $field, mixed $value): void
    {
        if (!is_bool($value)) {
            RequestException::throw("Field '{$field}' must be a boolean.");
        }
    }

    /**
     * Rule: 'numeric'
     * Checks whether the property value is a number using is_numeric().
     *
     * @param string $field The display name of the field.
     * @param mixed $value The value to validate.
     */
    private function validateNumeric(string $field, mixed $value): void
    {
        if (!is_numeric($value)) {
            RequestException::throw("Field '{$field}' must be numeric.");
        }
    }

    /**
     * Rule: 'array'
     * Checks whether a property's value is array using is_array().
     *
     * @param string $field The display name of the field.
     * @param mixed $value The value to validate.
     */
    private function validateArray(string $field, mixed $value): void
    {
        if (!is_array($value)) {
            RequestException::throw("Field '{$field}' must be a array.");
        }
    }

    /**
     * Rule: 'string'
     * Checks whether a property's value is a string using is_string().
     *
     * @param string $field The display name of the field.
     * @param mixed $value The value to validate.
     */
    private function validateString(string $field, mixed $value): void
    {
        if (!is_string($value)) {
            RequestException::throw("Field '{$field}' must be a string.");
        }
    }

    /**
     * Rule: 'length:min, max' или 'length:max'
     * Checks the length of the string representation of a property.
     * If only one parameter is passed, it is used as both min and max (exact length).
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     * @param string $min Minimum length.
     * @param string|null $max Maximum length (optional).
     */
    private function validateLength(string $field, mixed $value, string $min, ?string $max = null): void
    {
        $length = mb_strlen((string) $value);
        $max = $max ?? $min;

        if ($length < (int)$min || $length > (int)$max) {
            RequestException::throw("Field '{$field}' length must be between {$min} and {$max}.");
        }
    }

    /**
     * Rule: 'range:min, max'
     * Checks that the numeric value of a property is within the specified range.
     * Before checking, a check for is_numeric() is automatically performed.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     * @param string $min Minimum value.
     * @param string $max Maximum value.
     */
    private function validateRange(string $field, mixed $value, string $min, string $max): void
    {
        if (!is_numeric($value)) {
            RequestException::throw("Field '{$field}' must be numeric to use the 'range' rule.");
        }
        $value = (float) $value;

        if ($value < (float)$min || $value > (float)$max) {
            RequestException::throw("Field '{$field}' must be range {$min} and {$max}.");
        }
    }

    /**
     * Rule: 'in:value1,value2,...'
     * Checks if the field's value is in the given list.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     * @param mixed ...$allowedValues List of valid values.
     */
    private function validateIn(string $field, mixed $value, ...$allowedValues): void
    {
        if (!in_array($value, $allowedValues)) {
            $allowed = implode(', ', $allowedValues);
            RequestException::throw("Field '{$field}' must be one of: {$allowed}.");
        }
    }

    /**
     * Rule: 'Positive'
     * Ensures that the given value is numeric and greater than or equal to zero.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateNumberPositive(string $field, mixed $value): void
    {
        $this->validateNumeric($field, $value);

        if ((float)$value <= 0) {
            RequestException::throw("Field '{$field}' must be a positive number.");
        }
    }

    /**
     * Rule: 'Negative'
     * Ensures that the given value is numeric and greater than or equal to zero.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateNumberNegative(string $field, mixed $value): void
    {
        $this->validateNumeric($field, $value);

        if ((float)$value >= 0) {
            RequestException::throw("Field '{$field}' must be a negative number.");
        }
    }

    /**
     * Rule: 'uuid'
     * Ensures that the given value is a valid UUID (v1–v5).
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateUuid(string $field, mixed $value, bool $strict = true): void
    {
        if (!is_string($value)) {
            RequestException::throw("Field '{$field}' must be a string UUID.");
        }
        $pattern = $strict
            ? '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'
            : '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (!preg_match($pattern, $value)) {
            RequestException::throw("Field '{$field}' must be a valid UUID.");
        }
    }

    /**
     * Rule: 'email'
     * Validates that the field contains a properly formatted email address.
     * Uses PHP's built-in FILTER_VALIDATE_EMAIL filter.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateEmail(string $field, mixed $value): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            RequestException::throw("Field '{$field}' must be a valid email address.");
        }
    }

    /**
     * Rule: 'url'
     * Validates that the field contains a properly formatted URL.
     * Supports http, https, ftp and other standard URL schemes.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateUrl(string $field, mixed $value): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL)) {
            RequestException::throw("Field '{$field}' must be a valid URL.");
        }
    }

    /**
     * Rule: 'msisdn'
     * Validates that the field is a valid international phone number (MSISDN) in E.164 format.
     * The format must start with a plus sign (+), followed by 7 to 15 digits.
     * For example: +998901234567
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateMsisdn(string $field, mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^\+[1-9]\d{6,14}$/', $value)) {
            RequestException::throw("Field '{$field}' must be a valid MSISDN (E.164 format, e.g. +998901234567).");
        }
    }

    /**
     * Rule: 'phone'
     * Validates that the field contains a valid phone number.
     * Accepts both international and local formats (e.g. +998901234567, (90) 123-45-67, 901234567).
     * Allows spaces, hyphens, and parentheses.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validatePhone(string $field, mixed $value): void
    {
        if (!is_string($value) || !preg_match('/^\+?\d[\d\s\-\(\)]{5,20}$/', $value)) {
            RequestException::throw("Field '{$field}' must be a valid phone number.");
        }
    }

    /**
     * Rule: 'ip'
     * Validates that the field contains a valid IP address (IPv4 or IPv6).
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateIp(string $field, mixed $value): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP)) {
            RequestException::throw("Field '{$field}' must be a valid IP address (IPv4 or IPv6).");
        }
    }

    /**
     * Rule: 'ipv4'
     * Validates that the field contains a valid IPv4 address.
     * Uses PHP's FILTER_VALIDATE_IP with FILTER_FLAG_IPV4.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateIpv4(string $field, mixed $value): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            RequestException::throw("Field '{$field}' must be a valid IPv4 address.");
        }
    }

    /**
     * Rule: 'ipv6'
     * Validates that the field contains a valid IPv6 address.
     * Uses PHP's FILTER_VALIDATE_IP with FILTER_FLAG_IPV6.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     */
    private function validateIpv6(string $field, mixed $value): void
    {
        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            RequestException::throw("Field '{$field}' must be a valid IPv6 address.");
        }
    }

    /**
     * Rule: 'datetime'
     * Validates that the field contains a valid date-time string in the format `$format`.
     *
     * Uses `DateTime::createFromFormat()` for strict parsing and rejects invalid timestamps.
     *
     * @param string $field The name of the property being checked.
     * @param mixed $value The value to validate.
     * @param string $format
     */
    private function validateDatetime(string $field, mixed $value, string $format = 'Y-m-d H:i:s'): void
    {
        if (!is_string($value)) {
            RequestException::throw("Field '{$field}' must be a string datetime in format {$format}.");
        }

        $dateTime = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();

        if (!$dateTime || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            RequestException::throw("Field '{$field}' must match format {$format}.");
        }
    }
}
