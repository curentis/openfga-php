<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Credentials\CredentialsInterface;
use Curentis\OpenFga\Credentials\NoCredentials;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class ClientConfiguration
{
    public const string DEFAULT_API_URL = 'http://localhost:8080';

    public const int DEFAULT_MAX_BATCH_SIZE = 50;

    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        public string $apiUrl = self::DEFAULT_API_URL,
        public ?string $storeId = null,
        public ?string $authorizationModelId = null,
        public CredentialsInterface $credentials = new NoCredentials(),
        public RetryOptions $retry = new RetryOptions(),
        public array $defaultHeaders = [],
        public ?ClientInterface $httpClient = null,
        public ?RequestFactoryInterface $requestFactory = null,
        public ?StreamFactoryInterface $streamFactory = null,
        public ?CacheInterface $tokenCache = null,
        public ?ClientComponentFactoryInterface $componentFactory = null,
        public ?UriFactoryInterface $uriFactory = null,
        public ?string $tokenCacheKey = null,
        public SdkTelemetry $telemetry = new SdkTelemetry(),
    ) {
        $this->validate();
    }

    public function withStoreId(?string $storeId): self
    {
        return $this->copy(
            storeId: $storeId,
            authorizationModelId: $this->authorizationModelId,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            uriFactory: $this->uriFactory,
        );
    }

    public function withAuthorizationModelId(?string $authorizationModelId): self
    {
        return $this->copy(
            storeId: $this->storeId,
            authorizationModelId: $authorizationModelId,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            uriFactory: $this->uriFactory,
        );
    }

    public function withHttpStack(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        UriFactoryInterface $uriFactory,
    ): self {
        return $this->copy(
            storeId: $this->storeId,
            authorizationModelId: $this->authorizationModelId,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            uriFactory: $uriFactory,
        );
    }

    private function copy(
        ?string $storeId,
        ?string $authorizationModelId,
        ?ClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
        ?UriFactoryInterface $uriFactory,
    ): self {
        return new self(
            apiUrl: $this->apiUrl,
            storeId: $storeId,
            authorizationModelId: $authorizationModelId,
            credentials: $this->credentials,
            retry: $this->retry,
            defaultHeaders: $this->defaultHeaders,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            tokenCache: $this->tokenCache,
            componentFactory: $this->componentFactory,
            uriFactory: $uriFactory,
            tokenCacheKey: $this->tokenCacheKey,
            telemetry: $this->telemetry,
        );
    }

    private function validate(): void
    {
        $parts = parse_url($this->apiUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new FgaValidationException(sprintf('apiUrl "%s" must be an absolute http(s) URL.', $this->apiUrl));
        }
        if ($this->storeId !== null && !Ulid::isValid($this->storeId)) {
            throw new FgaValidationException('storeId must be a valid ULID.');
        }
        if ($this->authorizationModelId !== null && !Ulid::isValid($this->authorizationModelId)) {
            throw new FgaValidationException('authorizationModelId must be a valid ULID.');
        }
        if ($this->tokenCacheKey !== null) {
            if (!extension_loaded('sodium')) {
                // Environments without ext-sodium cannot execute this arm in CI.
                // @codeCoverageIgnoreStart
                throw new FgaValidationException('tokenCacheKey requires the sodium extension.');
                // @codeCoverageIgnoreEnd
            }
            if (strlen($this->tokenCacheKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new FgaValidationException(sprintf(
                    'tokenCacheKey must be %d bytes.',
                    SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                ));
            }
        }
    }
}
