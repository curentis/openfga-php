<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Psr\Http\Client\ClientInterface;

final class SequentialConcurrentSender implements ConcurrentSenderInterface
{
    public function __construct(private readonly ClientInterface $client) {}

    #[\Override]
    public function supportsParallel(): bool
    {
        return false;
    }

    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->client->sendRequest($request);
        }

        return $responses;
    }
}
