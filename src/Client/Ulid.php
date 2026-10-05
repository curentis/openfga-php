<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Exception\FgaValidationException;

final class Ulid
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;
    }

    public static function assert(string $value, string $name): void
    {
        if (!self::isValid($value)) {
            throw new FgaValidationException(sprintf('%s must be a valid ULID.', $name));
        }
    }
}
