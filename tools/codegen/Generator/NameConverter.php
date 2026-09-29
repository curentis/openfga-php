<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tools\Codegen\Generator;

final class NameConverter
{
    public static function schemaToClassName(string $schemaName): string
    {
        if ($schemaName === 'Object') {
            return 'FgaObject';
        }

        $name = $schemaName;
        if (str_starts_with($name, 'v1.')) {
            $name = substr($name, 3);
        }

        $parts = explode('.', $name);

        return implode('', array_map(
            static fn(string $part): string => self::toStudlyCase($part),
            $parts,
        ));
    }

    public static function jsonPropertyToPhp(string $jsonName): string
    {
        if ($jsonName === '') {
            return $jsonName;
        }

        if (str_starts_with($jsonName, '@')) {
            $jsonName = 'at_' . substr($jsonName, 1);
        }

        if ($jsonName === 'this') {
            return 'thisUserset';
        }

        $parts = explode('_', $jsonName);

        return $parts[0] . implode('', array_map(
            static fn(string $p): string => ucfirst($p),
            array_slice($parts, 1),
        ));
    }

    public static function phpPropertyToJson(string $phpName): string
    {
        if (str_starts_with($phpName, 'at') && strlen($phpName) > 2 && ctype_upper($phpName[2])) {
            return '@' . lcfirst(substr($phpName, 2));
        }

        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $phpName) ?? $phpName);
    }

    private static function toStudlyCase(string $value): string
    {
        $value = str_replace(['_', '-'], ' ', $value);
        $value = ucwords($value, ' ');

        return str_replace(' ', '', $value);
    }
}
