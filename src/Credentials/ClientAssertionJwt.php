<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

/**
 * @internal
 */
final class ClientAssertionJwt
{
    public static function sign(
        ClientAssertion $credentials,
        int $issuedAt,
        string $jti,
        int $ttlSeconds = 300,
    ): string {
        $header = ['alg' => $credentials->algorithm, 'typ' => 'JWT'];
        if ($credentials->keyId !== null && $credentials->keyId !== '') {
            $header['kid'] = $credentials->keyId;
        }

        $payload = [
            'iss' => $credentials->clientId,
            'sub' => $credentials->clientId,
            'aud' => IssuerUrl::audienceForJwt($credentials->apiTokenIssuer),
            'jti' => $jti,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttlSeconds,
        ];

        $headerJson = json_encode($header, JSON_THROW_ON_ERROR);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $segments = [
            self::base64UrlEncode($headerJson),
            self::base64UrlEncode($payloadJson),
        ];

        $signingInput = implode('.', $segments);
        $key = openssl_pkey_get_private($credentials->privateKeyPem);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key for client assertion.');
        }

        $signatureBytes = self::signSha256($signingInput, $key);
        $segments[] = self::base64UrlEncode($signatureBytes);

        return implode('.', $segments);
    }

    private static function signSha256(string $signingInput, \OpenSSLAsymmetricKey $key): string
    {
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            // @codeCoverageIgnoreStart
            throw new \RuntimeException('Failed to sign client assertion JWT.');
            // @codeCoverageIgnoreEnd
        }

        return self::requireNonEmptyBinaryString($signature);
    }

    private static function requireNonEmptyBinaryString(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            // @codeCoverageIgnoreStart
            throw new \RuntimeException('OpenSSL returned an empty client assertion signature.');
            // @codeCoverageIgnoreEnd
        }

        return $value;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
