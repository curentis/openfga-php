<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface TransportInterface
{
    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     */
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
    ): ResponseInterface;

    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     *
     * @return array<string, mixed>
     */
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
    ): array;

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<ResponseInterface>
     */
    public function sendAll(array $requests, int $maxParallel, ?string $storeId = null): array;

    public function supportsParallel(): bool;

    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     */
    public function buildRequest(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
    ): RequestInterface;
}
