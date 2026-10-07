<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

interface ConcurrentSenderInterface
{
    /**
     * Returns one outcome per request, in input order. Responses with any status are returned rather
     * than thrown; only transport failures are returned as exceptions.
     *
     * @param list<RequestInterface> $requests
     * @param positive-int           $maxParallel
     *
     * @return list<ResponseInterface|\Throwable>
     */
    public function send(array $requests, int $maxParallel): array;
}
