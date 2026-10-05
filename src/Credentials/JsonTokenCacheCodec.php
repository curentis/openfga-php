<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

final class JsonTokenCacheCodec implements TokenCacheCodec
{
    #[\Override]
    public function encode(string $accessToken, int $expiresAtEpoch): string
    {
        return json_encode(
            ['token' => $accessToken, 'expiresAt' => $expiresAtEpoch],
            JSON_THROW_ON_ERROR,
        );
    }

    #[\Override]
    public function decode(string $payload): ?array
    {
        try {
            /** @infection-ignore-all */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['token'], $decoded['expiresAt']) || !is_string($decoded['token'])) {
            return null;
        }

        if (is_int($decoded['expiresAt'])) {
            return [$decoded['token'], $decoded['expiresAt']];
        }

        if (is_string($decoded['expiresAt']) && ctype_digit($decoded['expiresAt'])) {
            return [$decoded['token'], (int) $decoded['expiresAt']];
        }

        return null;
    }
}
