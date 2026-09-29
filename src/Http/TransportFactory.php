<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\ClientConfiguration;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Random\Randomizer;

/**
 * @internal
 */
final class TransportFactory
{
    public static function create(
        ClientConfiguration $configuration,
        ?ClockInterface $clock = null,
        ?Sleeper $sleeper = null,
        ?Randomizer $randomizer = null,
        ?\Closure $tokenResolver = null,
    ): Transport {
        $httpClient = $configuration->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $configuration->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $configuration->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $uriFactory = Psr17FactoryDiscovery::findUriFactory();

        $retry = $configuration->retry;
        $retryPolicy = new RetryPolicy(
            $retry->maxRetry,
            $retry->minWaitMs,
            $sleeper ?? new SystemSleeper(),
            $clock ?? new NativeClock(),
            $randomizer ?? new Randomizer(),
        );

        /** @var ?\Closure(): string $oauthTokenResolver */
        $oauthTokenResolver = $tokenResolver;

        return new Transport(
            $configuration->apiUrl,
            $configuration->defaultHeaders,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $uriFactory,
            $retryPolicy,
            new AuthorizationHeaderProvider($configuration->credentials, $oauthTokenResolver),
        );
    }

    public static function discoverHttpClient(): ClientInterface
    {
        return Psr18ClientDiscovery::find();
    }
}
