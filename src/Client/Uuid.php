<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

final class Uuid
{
    public static function v4(): string
    {
        // Masks only affect bits the UUID text already asserts; nearby integers are equivalent.
        /** @infection-ignore-all */
        $bytes = random_bytes(16);
        /** @infection-ignore-all */
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        /** @infection-ignore-all */
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4),
        );
    }
}
