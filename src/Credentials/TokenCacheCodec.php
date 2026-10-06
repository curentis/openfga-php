<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

interface TokenCacheCodec
{
    public function encode(AccessToken $token): string;

    /**
     * Returns null for anything it cannot read, so a corrupt or foreign entry triggers a refresh.
     */
    public function decode(string $payload): ?AccessToken;
}
