<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApi;
use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Credentials\TokenProvider;
use Curentis\OpenFga\Http\NativeClock;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\SystemSleeper;
use Curentis\OpenFga\Http\TransportFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

final class DefaultOpenFgaClientFactory implements OpenFgaClientFactoryInterface
{
    #[\Override]
    public function create(
        ClientConfiguration $configuration,
        ?ClockInterface $clock = null,
        ?Randomizer $randomizer = null,
    ): OpenFgaClientInterface {
        if ($configuration->clientFactory !== null) {
            return $configuration->clientFactory->create($configuration, $clock, $randomizer);
        }

        if ($clock === null) {
            $clock = new NativeClock();
        }
        if ($randomizer === null) {
            $randomizer = new Randomizer();
        }
        $httpClient = $configuration->httpClient;
        if ($httpClient === null) {
            $httpClient = Psr18ClientDiscovery::find();
        }
        $requestFactory = $configuration->requestFactory;
        if ($requestFactory === null) {
            $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        }
        $streamFactory = $configuration->streamFactory;
        if ($streamFactory === null) {
            $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        }

        $retryPolicy = new RetryPolicy(
            $configuration->retry->maxRetry,
            $configuration->retry->minWaitMs,
            new SystemSleeper(),
            $clock,
            $randomizer,
        );

        /** @var ?\Closure(): string $tokenResolver */
        $tokenResolver = null;
        $credentials = $configuration->credentials;
        if ($credentials instanceof ClientCredentials || $credentials instanceof ClientAssertion) {
            $tokenProvider = new TokenProvider(
                $credentials,
                $httpClient,
                $requestFactory,
                $streamFactory,
                $retryPolicy,
                $clock,
                $randomizer,
                $configuration->tokenCache,
            );
            $tokenResolver = static function () use ($tokenProvider): string {
                return $tokenProvider->getAccessToken();
            };
        }

        $transport = TransportFactory::create(
            $configuration,
            $clock,
            new SystemSleeper(),
            $randomizer,
            $tokenResolver,
        );

        $api = new OpenFgaApi($transport);
        $componentFactory = $configuration->componentFactory;
        if ($componentFactory === null) {
            $componentFactory = new DefaultClientComponentFactory();
        }

        return new OpenFgaClient(
            $configuration,
            $api,
            $transport,
            $componentFactory->createWriteRunner($api),
            $componentFactory->createBatchCheckRunner($api),
            $componentFactory->createConsistencyBodyFactory(),
        );
    }
}
