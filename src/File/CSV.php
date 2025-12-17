<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\File;

/**
 * Class CSV
 *
 * `CSV` is a utility class for handling operations related to CSV files.
 * It includes static methods for reading, writing, and rewriting CSV files.
 *
 * The methods provided by `CSV` include:
 *
 * - `read(string $path, string $delimiter = ',', int $rowLength = 1000): array`: Reads a
 * CSV file and returns its contents as an array of associative arrays.
 * - `write(string $path, array $data): void`: Writes an array of data to a CSV file.
 * If the file already exists, it throws an error.
 *
 * @version 1.3
 * @author Flytachi
 */
abstract class CSV
{
    /**
     * Read a CSV file and return its contents as an array of associative arrays.
     *
     * @param string $path The path to the CSV file.
     * @param string $delimiter (optional) The character used to separate values in each line. Default is ','.
     * @param int $rowLength (optional) The maximum length of each line. Default is 1000.
     * @return array An array of associative arrays representing the CSV data.
     * @throws FileException
     */
    public static function read(string $path, string $delimiter = ',', int $rowLength = 1000): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new FileException('File does not exist or is not readable');
        }

        $header = null;
        $data = array();
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

    /**
     * Write an array of data to a CSV file.
     *
     * @param string $path The path to the CSV file.
     * @param array $data The data to be written to the CSV file.
     * @param array|null $head The head data to be written to the CSV file
     * @return void
     */
    public static function write(string $path, array $data, ?array $head = null): void
    {
        $file = fopen($path, 'w+');
        if ($head != null) {
            fputcsv($file, $head);
        } else {
            fputcsv($file, array_keys($data[0]));
        }
        foreach ($data as $line) {
            fputcsv($file, (array) $line);
        }
        fclose($file);
    }
}
