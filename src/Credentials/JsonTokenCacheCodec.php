<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

final class JsonTokenCacheCodec implements TokenCacheCodec
{
    #[\Override]
    public function encode(AccessToken $token): string
    {
        return json_encode(
            ['token' => $token->accessToken, 'expiresAt' => $token->expiresAtEpoch, 'refreshAt' => $token->refreshAtEpoch],
            JSON_THROW_ON_ERROR,
        );
    }

    #[\Override]
    public function decode(string $payload): ?AccessToken
    {
        try {
            /** @infection-ignore-all */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['token']) || !is_string($decoded['token'])) {
            return null;
        }

        $expiresAt = self::epoch($decoded['expiresAt'] ?? null);
        if ($expiresAt === null) {
            return null;
        }

        return new AccessToken($decoded['token'], $expiresAt, self::epoch($decoded['refreshAt'] ?? null));
    }

    private static function epoch(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
