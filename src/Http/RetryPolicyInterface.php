<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Psr\Http\Message\ResponseInterface;

interface RetryPolicyInterface
{
    /**
     * @param callable(): ResponseInterface $send
     */
    public function send(
        callable $send,
        string $method,
        string $endpoint,
        ?string $storeId = null,
        ?RetryOptions $retry = null,
        bool $idempotent = true,
    ): ResponseInterface;
}
