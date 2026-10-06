<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;

/**
 * Optional capability: a transport that can send independent calls concurrently.
 */
interface ParallelTransportInterface extends TransportInterface
{
    /**
     * Each call gets the same outcome as `sendJson()`: the same exceptions, retries, 401 refresh and
     * decoding. Implementations may send calls one after another when concurrency is unavailable.
     *
     * @param list<TransportCall> $calls
     * @param positive-int        $maxParallel
     *
     * @return list<array<string, mixed>> decoded bodies in the order of `$calls`
     *
     * @throws \Curentis\OpenFga\Exception\FgaException
     */
    public function sendJsonAll(array $calls, int $maxParallel, ?RetryOptions $retry = null): array;
}
