<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Factory\Entity;

class Request extends \stdClass implements RequestInterface
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
    public static function params(bool $required = true): static
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
    public static function formData(bool $required = true): static
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
            $class = new static();
            if (!empty($data)) {
                foreach ($data as $key => $value) {
                    $class->{dashAsciiToCamelCase($key)} = $value;
                }
            }
            return $class;
        } catch (\Exception $e) {
            RequestException::throw($e->getMessage(), previous: $e);
        }
    }
}
