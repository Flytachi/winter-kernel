<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\File;

/**
 * Class JSON
 *
 * `JSON` is a utility class for handling operations related to JSON files.
 * It includes static methods for reading, writing, and updating JSON files.
 *
 * The methods provided by `JsonFileHandler` include:
 *
 * - `read(string $path): array`: Reads a JSON file and returns its contents as an associative array.
 * - `write(string $path, array $data): void`: Writes an array of data to a JSON file.
 *
 * @version 1.3
 * @author Flytachi
 */
abstract class JSON
{
    /**
     * Reads the contents of a JSON file and returns it as an array.
     *
     * @param string $path The path to the JSON file.
     * @return array The array representation of the JSON file.
     * @throws FileException
     */
    public static function read(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new FileException('File does not exist or is not readable');
        }

        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new FileException('Error reading JSON file');
        }

        return $data;
    }

    /**
     * Writes the given array data to a JSON file.
     *
     * @param string $path The path to the JSON file.
     * @param array $data The array data to be written to the JSON file.
     * @return void
     * @throws FileException
     */
    public static function write(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);

        if (false === file_put_contents($path, $json)) {
            throw new FileException('Error writing JSON file');
        }
    }
}
