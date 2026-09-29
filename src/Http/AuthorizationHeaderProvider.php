<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Credentials\CredentialsInterface;
use Curentis\OpenFga\Credentials\NoCredentials;

/**
 * @internal
 */
final class AuthorizationHeaderProvider
{
    /** @var ?\Closure(): string */
    private ?\Closure $tokenResolver;

    /**
     * @param ?\Closure(): string $tokenResolver
     */
    public function __construct(
        private readonly CredentialsInterface $credentials,
        ?callable $tokenResolver = null,
    ) {
        $this->tokenResolver = $tokenResolver !== null ? $tokenResolver(...) : null;
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
}
