<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

interface TokenCacheCodec
{
    public function encode(string $accessToken, int $expiresAtEpoch): string;

    /**
     * @return array{0: string, 1: int}|null
     */
    public function decode(string $payload): ?array;
}
