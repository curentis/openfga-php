<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Psr\Http\Message\ResponseInterface;

interface TransportInterface
{
    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     *
     * @throws \Curentis\OpenFga\Exception\FgaException
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
     *
     * @throws \Curentis\OpenFga\Exception\FgaException
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
}
