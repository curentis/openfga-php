<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class ClientAssertion implements CredentialsInterface
{
    public function __construct(
        public string $clientId,
        public string $privateKeyPem,
        public string $apiTokenIssuer,
        public string $apiAudience,
        public string $algorithm = 'RS256',
        public ?string $keyId = null,
    ) {
        if ($clientId === '') {
            throw new FgaValidationException('clientId must not be empty.');
        }
        if ($privateKeyPem === '') {
            throw new FgaValidationException('privateKeyPem must not be empty.');
        }
        if ($apiTokenIssuer === '') {
            throw new FgaValidationException('apiTokenIssuer must not be empty.');
        }
        if ($apiAudience === '') {
            throw new FgaValidationException('apiAudience must not be empty.');
        }
        if ($algorithm !== 'RS256') {
            throw new FgaValidationException(sprintf('Only RS256 is supported, got "%s".', $algorithm));
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
            'privateKeyPem' => '***',
            'apiTokenIssuer' => $this->apiTokenIssuer,
            'apiAudience' => $this->apiAudience,
            'algorithm' => $this->algorithm,
            'keyId' => $this->keyId,
        ];
    }
}
