<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\File;

abstract class CSV
{
    /**
     * @throws FileException
     */
    public static function read(string $path, string $delimiter = ',', int $rowLength = 1000): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new FileException('File does not exist or is not readable');
        }

        $header = null;
        $data = [];
        if (($handle = fopen($path, 'r')) !== false) {
            while (($row = fgetcsv($handle, $rowLength, $delimiter)) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $data[] = array_combine($header, $row);
                }
            }
            fclose($handle);
        }

        return $data;
    }

    public static function write(string $path, array $data, ?array $head = null): void
    {
        $file = fopen($path, 'w+');
        fputcsv($file, $head ?? array_keys($data[0]));
        foreach ($data as $line) {
            fputcsv($file, (array) $line);
        }
        fclose($file);
    }
}
