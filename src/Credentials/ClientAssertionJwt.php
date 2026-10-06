<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\JsonBody;

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

        $headerJson = JsonBody::encode($header);
        $payloadJson = JsonBody::encode($payload);
        $segments = [
            self::base64UrlEncode($headerJson),
            self::base64UrlEncode($payloadJson),
        ];

        $signingInput = implode('.', $segments);
        $signatureBytes = self::signSha256($signingInput, $credentials->privateKey());
        $segments[] = self::base64UrlEncode($signatureBytes);

        return implode('.', $segments);
    }

    private static function signSha256(string $signingInput, \OpenSSLAsymmetricKey $key): string
    {
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            // @codeCoverageIgnoreStart
            throw new FgaValidationException('Failed to sign the client assertion JWT with privateKeyPem.');
            // @codeCoverageIgnoreEnd
        }

        return self::requireNonEmptyBinaryString($signature);
    }

    private static function requireNonEmptyBinaryString(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            // @codeCoverageIgnoreStart
            throw new FgaValidationException('OpenSSL returned an empty client assertion signature.');
            // @codeCoverageIgnoreEnd
        }

        return $value;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
