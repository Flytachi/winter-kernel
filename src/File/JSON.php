<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\File;

abstract class JSON
{
    /**
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
