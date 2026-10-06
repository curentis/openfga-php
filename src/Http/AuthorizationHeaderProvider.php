<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Credentials\CredentialsInterface;
use Curentis\OpenFga\Credentials\NoCredentials;

final class AuthorizationHeaderProvider
{
    /** @var ?\Closure(): string */
    private ?\Closure $tokenResolver;

    /** @var ?\Closure(): void */
    private ?\Closure $invalidateToken;

    /**
     * @param ?\Closure(): string $tokenResolver
     * @param ?\Closure(): void   $invalidateToken
     */
    public function __construct(
        private readonly CredentialsInterface $credentials,
        ?callable $tokenResolver = null,
        ?callable $invalidateToken = null,
    ) {
        $this->tokenResolver = $tokenResolver !== null ? $tokenResolver(...) : null;
        $this->invalidateToken = $invalidateToken !== null ? $invalidateToken(...) : null;
    }

    public function authorizationHeader(): ?string
    {
        if ($this->credentials instanceof ApiToken) {
            return 'Bearer ' . $this->credentials->token;
        }

        if ($this->credentials instanceof NoCredentials) {
            return null;
        }

        if ($this->tokenResolver !== null) {
            return 'Bearer ' . ($this->tokenResolver)();
        }

        return null;
    }

    public function invalidate(): bool
    {
        if ($this->invalidateToken === null) {
            return false;
        }

        ($this->invalidateToken)();

        return true;
    }
}
