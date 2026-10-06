<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApi;
use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Credentials\JsonTokenCacheCodec;
use Curentis\OpenFga\Credentials\SodiumTokenCacheCodec;
use Curentis\OpenFga\Credentials\TokenProvider;
use Curentis\OpenFga\Http\Adapter\ConcurrentSenderSelector;
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
        if ($clock === null) {
            $clock = new NativeClock();
        }
        if ($randomizer === null) {
            $randomizer = new Randomizer();
        }

        $httpClient = $configuration->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $configuration->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $configuration->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
        $uriFactory = $configuration->uriFactory ?? Psr17FactoryDiscovery::findUriFactory();
        $resolved = $configuration->withHttpStack($httpClient, $requestFactory, $streamFactory, $uriFactory);

        $sleeper = new SystemSleeper();
        $retryPolicy = RetryPolicy::fromOptions($resolved->retry, $sleeper, $clock, $randomizer, $resolved->telemetry);

        /** @var ?\Closure(): string $tokenResolver */
        $tokenResolver = null;
        /** @var ?\Closure(): void $invalidateToken */
        $invalidateToken = null;
        $credentials = $resolved->credentials;
        if ($credentials instanceof ClientCredentials || $credentials instanceof ClientAssertion) {
            $codec = $resolved->tokenCacheKey !== null
                ? new SodiumTokenCacheCodec($resolved->tokenCacheKey)
                : new JsonTokenCacheCodec();
            $tokenProvider = new TokenProvider(
                $credentials,
                $httpClient,
                $requestFactory,
                $streamFactory,
                $retryPolicy,
                $clock,
                $randomizer,
                $resolved->tokenCache,
                $codec,
                $resolved->telemetry,
            );
            $tokenResolver = static function () use ($tokenProvider): string {
                return $tokenProvider->getAccessToken();
            };
            $invalidateToken = static function () use ($tokenProvider): void {
                $tokenProvider->invalidate();
            };
        }

        $transport = TransportFactory::create(
            $resolved,
            $clock,
            $sleeper,
            $randomizer,
            $tokenResolver,
            $retryPolicy,
            $invalidateToken,
            ConcurrentSenderSelector::select($httpClient),
        );

        $api = new OpenFgaApi($transport);
        $componentFactory = $resolved->componentFactory ?? new DefaultClientComponentFactory();

        return new OpenFgaClient(
            $resolved,
            $api,
            $transport,
            $componentFactory->createWriteRunner($api),
            $componentFactory->createBatchCheckRunner($api, $transport),
            $componentFactory->createConsistencyBodyFactory(),
        );
    }
}
