<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http\Adapter;

use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;

final class ConcurrentSenderSelector
{
    public static function select(ClientInterface $client): ?ConcurrentSenderInterface
    {
        // Autoloading Guzzle still returns null for every other client.
        /** @infection-ignore-all */
        if (class_exists(Client::class, false) && $client instanceof Client) {
            return new GuzzleConcurrentSender($client);
        }

        return null;
    }
}
