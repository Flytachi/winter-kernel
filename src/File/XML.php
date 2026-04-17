<?php

declare(strict_types=1);

namespace Flytachi\Winter\K2\File;

abstract class XML
{
    /**
     * @throws FileException
     */
    public static function read(string $filePath): array
    {
        self::requireExtension();
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new FileException('File does not exist or is not readable');
        }
        return json_decode(json_encode(simplexml_load_file($filePath)), true);
    }

    /**
     * @throws FileException
     */
    public static function write(string $filePath, array $content, string $rootElement = 'root'): void
    {
        self::requireExtension();
        try {
            $xml = new \SimpleXMLElement(
                '<?xml version="1.0" encoding="UTF-8"?><' . $rootElement . '></' . $rootElement . '>'
            );
        } catch (\Exception $e) {
            throw new FileException($e->getMessage());
        }

        self::convertArrayToXml($content, $xml);
        if (false === $xml->asXML($filePath)) {
            throw new FileException('Error writing XML file');
        }
    }

    /**
     * @throws FileException
     */
    public static function stringToArray(string $xmlString): array
    {
        self::requireExtension();
        $xmlObject = simplexml_load_string($xmlString);
        if ($xmlObject === false) {
            throw new FileException('Error parsing XML string');
        }
        return json_decode(json_encode($xmlObject), true);
    }

    /**
     * @throws FileException
     */
    public static function arrayToXml(array $data, string $rootElement = 'root', array $attrs = []): string
    {
        self::requireExtension();
        try {
            $xml = new \SimpleXMLElement(
                '<?xml version="1.0" encoding="UTF-8"?><' . $rootElement . '></' . $rootElement . '>'
            );
        } catch (\Exception $e) {
            throw new FileException($e->getMessage());
        }
        foreach ($attrs as $attr => $value) {
            $xml->addAttribute($attr, $value);
        }
        self::convertArrayToXml($data, $xml);
        return $xml->asXML();
    }

    /** Returns true if ext-simplexml is available, false otherwise. */
    public static function isAvailable(): bool
    {
        return extension_loaded('simplexml');
    }

    /** @throws FileException if ext-simplexml is not loaded */
    private static function requireExtension(): void
    {
        if (!extension_loaded('simplexml')) {
            throw new FileException('ext-simplexml is required for XML operations but is not loaded');
        }
    }

    private static function convertArrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $node = $xml->addChild(is_numeric($key) ? 'item.' . $key : $key);
                self::convertArrayToXml($value, $node);
            } else {
                $xml->addChild(
                    is_numeric($key) ? 'item.' . $key : $key,
                    htmlspecialchars((string) $value)
                );
            }
        }
    }
}
