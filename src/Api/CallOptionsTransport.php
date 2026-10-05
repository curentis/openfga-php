<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Api;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Http\TransportInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CallOptionsTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $inner,
        private readonly ?RetryOptions $retry,
    ) {}

    #[\Override]
    public function send(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
        ?string $storeId = null,
        ?RetryOptions $retry = null,
        bool $idempotent = true,
    ): ResponseInterface {
        return $this->inner->send(
            $method,
            $pathTemplate,
            $pathParams,
            $query,
            $body,
            $requestHeaders,
            $storeId,
            $retry ?? $this->retry,
            $idempotent,
        );
    }

    #[\Override]
    public function sendJson(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
        ?string $storeId = null,
        ?RetryOptions $retry = null,
        bool $idempotent = true,
    ): array {
        return $this->inner->sendJson(
            $method,
            $pathTemplate,
            $pathParams,
            $query,
            $body,
            $requestHeaders,
            $storeId,
            $retry ?? $this->retry,
            $idempotent,
        );
    }

    #[\Override]
    public function sendAll(array $requests, int $maxParallel, ?string $storeId = null): array
    {
        return $this->inner->sendAll($requests, $maxParallel, $storeId);
    }

    #[\Override]
    public function supportsParallel(): bool
    {
        return $this->inner->supportsParallel();
    }

    #[\Override]
    public function buildRequest(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
    ): RequestInterface {
        return $this->inner->buildRequest($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders);
    }
}
