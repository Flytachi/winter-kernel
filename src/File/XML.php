<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\File;

use SimpleXMLElement;

/**
 * Class XML
 *
 * `XML` is a utility class designed to handle operations related to XML files.
 * This class includes methods for reading XML files, converting XML strings to arrays, and converting arrays to XML.
 *
 * The methods provided by `XML` include:
 *
 * - `read(string $filePath): array`: Reads an XML file and returns its contents as an associative array.
 * - `write(string $path, array $content): void`: Writes content to an XML file.
 * - `stringToArray(string $xmlString): array`: Converts an XML string to an associative array.
 * - `arrayToXml(array $data): string`: Converts an array to an XML string format.
 *
 * @version 1.4
 * @author Flytachi
 */
abstract class XML
{
    /**
     * Reads content of an XML file and returns it as an array.
     *
     * @param string $filePath
     * @return array
     * @throws FileException
     */
    public static function read(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new FileException("File does not exist or is not readable");
        }
        return json_decode(json_encode(simplexml_load_file($filePath)), true);
    }

    /**
     * Writes content to an XML file.
     *
     * @param string $filePath The file path where the XML file will be written.
     * @param array $content The content to be written to the XML file.
     * @param string $rootElement The root element of the XML file. Default is 'root'.
     * @throws FileException If there is an error writing the XML file.
     */
    public static function write(string $filePath, array $content, string $rootElement = 'root'): void
    {
        try {
            $xml = new SimpleXMLElement(
                '<?xml version="1.0" encoding="UTF-8"?><' . $rootElement . '></' . $rootElement . '>'
            );
        } catch (\Exception $exception) {
            throw new FileException($exception->getMessage());
        }

        self::convertArrayToXml($content, $xml);
        if (false === $xml->asXML($filePath)) {
            throw new FileException('Error writing XML file');
        }
    }

    /**
     * Converts an XML string into an array.
     *
     * @param string $xmlString The XML string to convert.
     * @return array The converted XML string as an array.
     * @throws FileException If there is an error parsing the XML string.
     */
    public static function stringToArray(string $xmlString): array
    {
        $xmlObject = simplexml_load_string($xmlString);
        if ($xmlObject === false) {
            throw new FileException('Error parsing XML string');
        }
        return json_decode(json_encode($xmlObject), true);
    }

    /**
     * Converts an array to an XML string.
     *
     * @param array $data The array to be converted to XML format.
     * @return string The array in XML format.
     * @throws FileException
     */
    public static function arrayToXml(array $data, string $rootElement = 'root', array $attrs = []): string
    {
        try {
            $xml = new SimpleXMLElement(
                '<?xml version="1.0" encoding="UTF-8"?><' . $rootElement . '></' . $rootElement . '>'
            );
        } catch (\Exception $exception) {
            throw new FileException($exception->getMessage());
        }
        foreach ($attrs as $attr => $value) {
            $xml->addAttribute($attr, $value);
        }
        self::convertArrayToXml($data, $xml);
        return $xml->asXML();
    }

    private static function convertArrayToXml(array $data, SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $newNode = $xml->addChild((is_numeric($key) ? 'item.' . $key : $key));
                self::convertArrayToXml($value, $newNode);
            } else {
                $xml->addChild((is_numeric($key) ? 'item.' . $key : $key), htmlspecialchars((string) $value));
            }
        }
    }
}
