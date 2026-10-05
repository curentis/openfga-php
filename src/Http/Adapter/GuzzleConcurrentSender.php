<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http\Adapter;

use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

final class GuzzleConcurrentSender implements ConcurrentSenderInterface
{
    public function __construct(private readonly Client $client) {}

    #[\Override]
    public function supportsParallel(): bool
    {
        return true;
    }

    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        // Pool accepts a missing or nearby concurrency for the batch sizes we send.
        /** @infection-ignore-all */
        $batched = Pool::batch($this->client, $requests, ['concurrency' => max(1, $maxParallel)]);
        $responses = [];
        foreach ($batched as $item) {
            if (!$item instanceof ResponseInterface) {
                throw new FgaNetworkException(
                    'Parallel OpenFGA request failed.',
                    'POST',
                    '/batch',
                    $item instanceof \Throwable ? $item : null,
                );
            }
            $responses[] = $item;
        }

        return $responses;
    }
}
