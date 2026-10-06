<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Curentis\OpenFga\Api\OpenFgaApi;
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\DefaultClientComponentFactory;
use Curentis\OpenFga\Client\OpenFgaClient;
use Curentis\OpenFga\Credentials\NoCredentials;
use Curentis\OpenFga\Http\AuthorizationHeaderProvider;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\Transport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

abstract class MockTransportTestCase extends TestCase
{
    protected function openFgaApi(MockClient $mock): OpenFgaApi
    {
        return new OpenFgaApi($this->transport($mock));
    }

    protected function openFgaClient(
        MockClient $mock,
        string $storeId = '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ?string $authorizationModelId = '01HZZZZZZZZZZZZZZZZZZZZZZZ',
    ): OpenFgaClient {
        return $this->openFgaClientWithConfiguration(
            $mock,
            new ClientConfiguration(
                storeId: $storeId,
                authorizationModelId: $authorizationModelId,
            ),
        );
    }

    protected function openFgaClientWithConfiguration(MockClient $mock, ClientConfiguration $configuration): OpenFgaClient
    {
        $transport = $this->transport($mock);
        $api = new OpenFgaApi($transport);
        $components = new DefaultClientComponentFactory();

        return new OpenFgaClient(
            $configuration,
            $api,
            $transport,
            $components->createWriteRunner($api),
            $components->createBatchCheckRunner($api, $transport),
            $components->createConsistencyBodyFactory(),
        );
    }

    protected function lastRequest(MockClient $mock): RequestInterface
    {
        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    protected function openFgaClientFromHttp(
        ClientInterface $http,
        string $storeId = '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ?string $authorizationModelId = '01HZZZZZZZZZZZZZZZZZZZZZZZ',
    ): OpenFgaClient {
        $transport = $this->transportFor($http);
        $api = new OpenFgaApi($transport);
        $components = new DefaultClientComponentFactory();

        return new OpenFgaClient(
            new ClientConfiguration(
                storeId: $storeId,
                authorizationModelId: $authorizationModelId,
            ),
            $api,
            $transport,
            $components->createWriteRunner($api),
            $components->createBatchCheckRunner($api, $transport),
            $components->createConsistencyBodyFactory(),
        );
    }

    protected function transport(MockClient $mock): Transport
    {
        return $this->transportFor($mock);
    }

    protected function retryingApi(MockClient $mock): OpenFgaApi
    {
        $factories = new Psr17Factory();
        $retry = new RetryPolicy(
            0,
            1,
            new FakeSleeper(),
            new FrozenClock(new \DateTimeImmutable('@1700000000')),
            new Randomizer(new Mt19937(1)),
        );

        return new OpenFgaApi(new Transport(
            'http://localhost:8080',
            [],
            $mock,
            $factories,
            $factories,
            $factories,
            $retry,
            new AuthorizationHeaderProvider(new NoCredentials()),
        ));
    }

    protected function transportFor(ClientInterface $http): Transport
    {
        $factories = new Psr17Factory();
        $retry = new RetryPolicy(
            0,
            100,
            new FakeSleeper(),
            new FrozenClock(new \DateTimeImmutable('@1700000000')),
            new Randomizer(new Mt19937(1)),
        );

        return new Transport(
            'http://localhost:8080',
            [],
            $http,
            $factories,
            $factories,
            $factories,
            $retry,
            new AuthorizationHeaderProvider(new NoCredentials()),
        );
    }
}
