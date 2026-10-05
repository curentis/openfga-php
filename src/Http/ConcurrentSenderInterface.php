<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ConcurrentSenderInterface
{
    public function supportsParallel(): bool;

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<ResponseInterface>
     */
    public function send(array $requests, int $maxParallel): array;
}
