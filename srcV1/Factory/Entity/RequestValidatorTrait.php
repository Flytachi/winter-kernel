<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Entity;

trait RequestValidatorTrait
{
    /**
     * Validate Field
     *
     * Checking the existence of a value in the data.
     *
     * If you set the argument "validateFunc" will check the
     * data on the function with the condition that the
     * function returns a bool value, and takes 1 argument
     *
     * @param string $fieldName field name -> array key
     * @param callable|null $validateFunc validation func returned bool!
     * @param string|null $message message with incorrect validation in func
     *
     * @return self
     */
    final public function valid(string $fieldName, ?callable $validateFunc = null, ?string $message = null): static
    {
        try {
            if (!isset($this->$fieldName)) {
                RequestException::throw("Required field '{$fieldName}' not found");
            }
            if ($validateFunc !== null) {
                if (!$validateFunc($this->$fieldName)) {
                    RequestException::throw("{$fieldName} - " . ($message ?? "field has the wrong data type"));
                }
            }
        } catch (\Throwable $exception) {
            RequestException::throw($exception->getMessage());
        }
        return $this;
    }

    /**
     * Validates a field by a given filter and throws a RequestError if the field is invalid.
     *
     * @param string $fieldName The name of the field to validate.
     * @param int $filter The filter to apply to the field. Defaults to FILTER_DEFAULT.
     * @param string|null $message The custom error message to use if the field is invalid. Defaults to null.
     *
     * @return static The current instance of the class.
     */
    final public function validByFilter(
        string $fieldName,
        int $filter = FILTER_DEFAULT,
        ?string $message = null
    ): static {
        try {
            if (!isset($this->$fieldName)) {
                RequestException::throw("Required field '{$fieldName}' not found");
            }
            if (!filter_var($this->$fieldName, $filter)) {
                RequestException::throw("{$fieldName} - " . ($message ?? "field has the wrong data type"));
            }
        } catch (\Throwable $exception) {
            RequestException::throw($exception->getMessage());
        }
        return $this;
    }
}
