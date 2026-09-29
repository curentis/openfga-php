<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class ClientCredentials implements CredentialsInterface
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $apiTokenIssuer,
        public string $apiAudience,
        public ?string $scopes = null,
    ) {
        if ($clientId === '') {
            throw new FgaValidationException('clientId must not be empty.');
        }
        if ($clientSecret === '') {
            throw new FgaValidationException('clientSecret must not be empty.');
        }
        if ($apiTokenIssuer === '') {
            throw new FgaValidationException('apiTokenIssuer must not be empty.');
        }
        if ($apiAudience === '') {
            throw new FgaValidationException('apiAudience must not be empty.');
        }
    }

    public function tokenEndpoint(): string
    {
        return IssuerUrl::tokenEndpoint($this->apiTokenIssuer);
    }

    /**
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => '***',
            'apiTokenIssuer' => $this->apiTokenIssuer,
            'apiAudience' => $this->apiAudience,
            'scopes' => $this->scopes,
        ];
    }
}
