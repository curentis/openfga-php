<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

/**
 * @internal
 */
final readonly class AccessToken
{
    /** Epoch second from which the token should be replaced; it stays usable until `$expiresAtEpoch`. */
    public int $refreshAtEpoch;

    public function __construct(
        public string $accessToken,
        public int $expiresAtEpoch,
        ?int $refreshAtEpoch = null,
    ) {
        $this->refreshAtEpoch = $refreshAtEpoch ?? $expiresAtEpoch;
    }

    public function isExpiredAt(int $nowEpoch): bool
    {
        return $nowEpoch >= $this->expiresAtEpoch;
    }

    public function needsRefreshAt(int $nowEpoch): bool
    {
        return $nowEpoch >= $this->refreshAtEpoch;
    }
}
