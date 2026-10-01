<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

/**
 * @internal
 */
final class Uuid
{
    public static function v4(): string
    {
        return self::formatV4Bytes(random_bytes(16));
    }

    /**
     * @internal
     */
    public static function formatV4Bytes(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('UUID v4 requires exactly 16 bytes.');
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4),
        );
    }
}
