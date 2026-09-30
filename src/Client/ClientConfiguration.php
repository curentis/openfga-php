<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Credentials\CredentialsInterface;
use Curentis\OpenFga\Credentials\NoCredentials;
use Curentis\OpenFga\Exception\FgaValidationException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;

final readonly class ClientConfiguration
{
    public const string DEFAULT_API_URL = 'http://localhost:8080';

    public const int DEFAULT_MAX_BATCH_SIZE = 50;

    public const int DEFAULT_MAX_PARALLEL_REQUESTS = 10;

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
        public float $timeoutSeconds = 10.0,
        public ?OpenFgaClientFactoryInterface $clientFactory = null,
        public ?ClientComponentFactoryInterface $componentFactory = null,
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        $parts = parse_url($this->apiUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new FgaValidationException(sprintf('apiUrl "%s" must be an absolute http(s) URL.', $this->apiUrl));
        }
        if ($this->storeId !== null && !self::isUlid($this->storeId)) {
            throw new FgaValidationException('storeId must be a valid ULID.');
        }
        if ($this->authorizationModelId !== null && !self::isUlid($this->authorizationModelId)) {
            throw new FgaValidationException('authorizationModelId must be a valid ULID.');
        }
    }

    private static function isUlid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;
    }

    public function withStoreId(?string $storeId): self
    {
        return new self(
            apiUrl: $this->apiUrl,
            storeId: $storeId,
            authorizationModelId: $this->authorizationModelId,
            credentials: $this->credentials,
            retry: $this->retry,
            defaultHeaders: $this->defaultHeaders,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            tokenCache: $this->tokenCache,
            timeoutSeconds: $this->timeoutSeconds,
            clientFactory: $this->clientFactory,
            componentFactory: $this->componentFactory,
        );
    }

    public function withAuthorizationModelId(?string $authorizationModelId): self
    {
        return new self(
            apiUrl: $this->apiUrl,
            storeId: $this->storeId,
            authorizationModelId: $authorizationModelId,
            credentials: $this->credentials,
            retry: $this->retry,
            defaultHeaders: $this->defaultHeaders,
            httpClient: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            tokenCache: $this->tokenCache,
            timeoutSeconds: $this->timeoutSeconds,
            clientFactory: $this->clientFactory,
            componentFactory: $this->componentFactory,
        );
    }
}
