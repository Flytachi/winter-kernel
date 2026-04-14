<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Entity;

use ArgumentCountError;
use Error;
use Flytachi\Winter\Base\Header;
use TypeError;

abstract class RequestBase implements RequestInterface
{
    use ValidateTrait;
    use RequestValidatorTrait;

    /**
     * Retrieves the GET data from the request.
     *
     * @param bool $required (Optional) Specifies whether the GET data is required. Default is true.
     *
     * @return static A new instance of the class representing the GET data from the request.
     */
    final public static function params(bool $required = true): static
    {
        if ($required && !$_GET) {
            RequestException::throw("Missing required data for request");
        }
        return self::from($_GET);
    }

    /**
     * Retrieves the POST data from the request.
     *
     * @param bool $required (Optional) Specifies whether the POST data is required. Default is true.
     *
     * @return static A new instance of the class representing the POST data from the request.
     */
    final public static function formData(bool $required = true): static
    {
        if ($required && !$_POST) {
            RequestException::throw("Missing required data for request");
        }
        return self::from($_POST);
    }

    /**
     * Retrieves the JSON data from the request.
     *
     * @param bool $required (Optional) Specifies whether the JSON data is required. Default is true.
     *
     * @return static A new instance of the class representing the JSON data from the request.
     */
    final public static function json(bool $required = true): static
    {
        $data = file_get_contents('php://input');
        if ($required && (!$data || !json_validate($data))) {
            RequestException::throw("Missing required data for request");
        }
        return self::from(json_decode($data, true));
    }

    /**
     * @param mixed $data
     * @return static
     */
    private static function from(mixed $data): static
    {
        try {
            if (empty($data)) {
                return new static();
            } else {
                foreach ($data as $key => $value) {
                    $newKey = dashAsciiToCamelCase($key);
                    if ($newKey !== $key) {
                        $data[$newKey] = $value;
                        unset($data[$key]);
                    }
                }
                return new static(...$data);
            }
        } catch (ArgumentCountError $e) {
            $errorMessage = preg_replace(
                '/.*Argument #\d+ \(\$(\w+)\) not passed/',
                'Required field \'$1\' not found',
                $e->getMessage()
            );
            $errorMessage = preg_replace(
                '/Too few arguments to function .*, (\d+) passed .*/',
                'Missing required data for request',
                $errorMessage
            );

            RequestException::throw($errorMessage, previous: $e);
        } catch (TypeError $e) {
            $errorMessage = preg_replace(
                '/.*Argument #\d+ \(\$(\w+)\) must be of type (\w+), (\w+) given.*/',
                "Invalid type field '$1' (required: '$2', given: '$3')",
                $e->getMessage()
            );
            RequestException::throw($errorMessage, previous: $e);
        } catch (Error $e) {
            $errorMessage = preg_replace(
                '/Unknown named parameter \$(\w+)/',
                "Undefined field '$1'",
                $e->getMessage()
            );
            RequestException::throw($errorMessage, previous: $e);
        }
    }

    final public function header(?string $key = null): array|string
    {
        return $key == null
            ? Header::getHeaders()
            : Header::getHeader($key);
    }
}
