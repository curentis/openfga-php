<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class ClientAssertion implements CredentialsInterface
{
    private readonly \OpenSSLAsymmetricKey $privateKey;

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
        $this->privateKey = self::loadPrivateKey($privateKeyPem);
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

    public function privateKey(): \OpenSSLAsymmetricKey
    {
        return $this->privateKey;
    }

    private static function loadPrivateKey(string $pem): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new FgaValidationException('privateKeyPem must be a valid RSA private key of at least 2048 bits.');
        }
        $details = openssl_pkey_get_details($key);
        // OpenSSL always returns an int bit length for a key it just parsed.
        /** @infection-ignore-all */
        $bits = is_array($details) ? ($details['bits'] ?? 0) : 0;
        /** @infection-ignore-all */
        $type = is_array($details) ? ($details['type'] ?? null) : null;
        /** @infection-ignore-all */
        if ($type !== OPENSSL_KEYTYPE_RSA || !is_int($bits) || $bits < 2048) {
            throw new FgaValidationException('privateKeyPem must be a valid RSA private key of at least 2048 bits.');
        }

        return $key;
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
