<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;

final class IssuerUrl
{
    public static function normalize(string $issuer): string
    {
        $trimmed = trim($issuer);
        if ($trimmed === '') {
            throw new FgaValidationException('Issuer must not be empty.');
        }
        if (preg_match('#^https?://#i', $trimmed) !== 1) {
            $trimmed = 'https://' . $trimmed;
        }
        self::assertSecure($trimmed);

        return rtrim($trimmed, '/');
    }

    public static function tokenEndpoint(string $issuer): string
    {
        $normalized = self::normalize($issuer);
        $parts = parse_url($normalized);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        if ($path !== '' && $path !== '/') {
            return $normalized;
        }

        return $normalized . '/oauth/token';
    }

    public static function audienceForJwt(string $issuer): string
    {
        return self::normalize($issuer) . '/';
    }

    private static function assertSecure(string $issuer): void
    {
        if (preg_match('#^http://#i', $issuer) !== 1) {
            return;
        }

        $host = parse_url($issuer, PHP_URL_HOST);
        if (is_string($host)) {
            $host = strtolower(trim($host, '[]'));
        }
        if (!is_string($host) || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new FgaValidationException('apiTokenIssuer must use https except for localhost.');
        }
    }
}
