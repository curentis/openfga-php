<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http\Adapter;

use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

final class GuzzleConcurrentSender implements ConcurrentSenderInterface
{
    public function __construct(private readonly Client $client) {}

    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        // `sendAsync()` does not apply the `http_errors => false` that `sendRequest()` sets, so every
        // status must be requested explicitly for the transport to map it.
        $batched = Pool::batch($this->client, $requests, [
            'concurrency' => $maxParallel,
            'options' => ['http_errors' => false],
        ]);
        $outcomes = [];
        /** @var mixed $item */
        foreach ($batched as $item) {
            $outcomes[] = $item instanceof ResponseInterface || $item instanceof \Throwable
                ? $item
                : new \RuntimeException('Parallel request was rejected without an exception.');
        }

        return $outcomes;
    }
}
