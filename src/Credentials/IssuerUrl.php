<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

/**
 * @internal
 */
final class IssuerUrl
{
    public static function normalize(string $issuer): string
    {
        $trimmed = trim($issuer);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Issuer must not be empty.');
        }

        if (preg_match('#^https?://#i', $trimmed) !== 1) {
            $trimmed = 'https://' . $trimmed;
        }

        return rtrim($trimmed, '/');
    }

    public static function tokenEndpoint(string $issuer): string
    {
        $normalized = self::normalize($issuer);
        $parts = parse_url($normalized);
        $path = $parts['path'] ?? '';

        if ($path !== '' && $path !== '/') {
            return $normalized;
        }

        return $normalized . '/oauth/token';
    }

    public static function audienceForJwt(string $issuer): string
    {
        return self::normalize($issuer) . '/';
    }
}
