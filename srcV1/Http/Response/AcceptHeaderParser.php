<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Http\Response;

final class AcceptHeaderParser
{
    /**
     * @param string $acceptHeader
     * @return ContentType
     */
    public static function getBestMatch(string $acceptHeader): ContentType
    {
        if (empty($acceptHeader)) {
            return ContentType::UNDEFINED;
        }

        $priorities = [];
        $accepted = explode(',', $acceptHeader);

        foreach ($accepted as $accept) {
            $parts = explode(';', $accept);
            $type = trim($parts[0]);
            $quality = 1.0;

            if (isset($parts[1]) && str_starts_with(trim($parts[1]), 'q=')) {
                $quality = (float) substr(trim($parts[1]), 2);
            }
            $priorities[$type] = $quality;
        }

        arsort($priorities);
        $supportedValues = array_map(fn($case) => $case->value, ContentType::cases());

        foreach (array_keys($priorities) as $type) {
            if ($type === '*/*') {
                return ContentType::UNDEFINED;
            }
            if (in_array($type, $supportedValues, true)) {
                return ContentType::from($type);
            }
        }

        return ContentType::UNDEFINED;
    }
}
