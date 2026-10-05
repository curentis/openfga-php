<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\ClientConfiguration;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

final class TransportFactory
{
    /**
     * @param ?\Closure(): string $tokenResolver
     * @param ?\Closure(): void   $invalidateToken
     */
    public static function create(
        ClientConfiguration $configuration,
        ?ClockInterface $clock = null,
        ?Sleeper $sleeper = null,
        ?Randomizer $randomizer = null,
        ?\Closure $tokenResolver = null,
        ?RetryPolicy $retryPolicy = null,
        ?\Closure $invalidateToken = null,
        ?ConcurrentSenderInterface $concurrentSender = null,
    ): Transport {
        $httpClient = $configuration->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $configuration->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $configuration->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $uriFactory = $configuration->uriFactory ?? Psr17FactoryDiscovery::findUriFactory();

        $clock ??= new NativeClock();
        $randomizer ??= new Randomizer();
        $retry = $configuration->retry;
        // Swapping the operands would construct SystemSleeper and sleep for real.
        /** @infection-ignore-all */
        $resolvedSleeper = $sleeper ?? new SystemSleeper();
        $retryPolicy ??= new RetryPolicy(
            $retry->maxRetry,
            $retry->minWaitMs,
            $resolvedSleeper,
            $clock,
            $randomizer,
            maxElapsedMs: $retry->maxElapsedMs,
            maxDelayMs: $retry->maxDelayMs,
            telemetry: $configuration->telemetry,
        );

        return new Transport(
            $configuration->apiUrl,
            $configuration->defaultHeaders,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $uriFactory,
            $retryPolicy,
            new AuthorizationHeaderProvider($configuration->credentials, $tokenResolver, $invalidateToken),
            $concurrentSender ?? new SequentialConcurrentSender($httpClient),
        );
    }
}
