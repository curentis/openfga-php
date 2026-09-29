<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

/**
 * @internal
 */
final readonly class AccessToken
{
    public function __construct(
        public string $accessToken,
        public int $expiresAtEpoch,
    ) {}

    public function isExpiredAt(int $nowEpoch): bool
    {
        return $nowEpoch >= $this->expiresAtEpoch;
    }
}
