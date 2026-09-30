<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\DefaultOpenFgaClientFactory;
use Curentis\OpenFga\Client\OpenFgaClient;
use Curentis\OpenFga\Client\OpenFgaClientFactoryInterface;
use Curentis\OpenFga\Client\OpenFgaClientInterface;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;

final class DefaultOpenFgaClientFactoryTest extends MockTransportTestCase
{
    public function testCreateBuildsWorkingClientWithExplicitHttpDependencies(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));
        $factories = new Psr17Factory();

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                httpClient: $mock,
                requestFactory: $factories,
                streamFactory: $factories,
            ),
        );

        self::assertSame([], $client->listStores()->stores);
        self::assertCount(1, $mock->getRequests());
    }

    public function testCreateUsesHttpDiscoveryWhenDependenciesOmitted(): void
    {
        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(apiUrl: 'http://localhost:8080'),
        );

        self::assertInstanceOf(OpenFgaClient::class, $client);
    }

    public function testCreateUsesDiscoveryForMissingHttpFactoriesWhenClientIsProvided(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
            ),
        );

        self::assertSame([], $client->listStores()->stores);
        self::assertCount(1, $mock->getRequests());
    }

    public function testCreateDelegatesToClientFactoryOnConfiguration(): void
    {
        $mock = new MockClient();
        $expected = $this->openFgaClient($mock);

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                clientFactory: new FixedOpenFgaClientFactory($expected),
            ),
        );

        self::assertSame($expected, $client);
    }
}

final class FixedOpenFgaClientFactory implements OpenFgaClientFactoryInterface
{
    public function __construct(private readonly OpenFgaClientInterface $client) {}

    #[\Override]
    public function create(
        ClientConfiguration $configuration,
        ?\Psr\Clock\ClockInterface $clock = null,
        ?\Random\Randomizer $randomizer = null,
    ): OpenFgaClientInterface {
        return $this->client;
    }
}
