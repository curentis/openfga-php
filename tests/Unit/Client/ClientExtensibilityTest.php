<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Client\BatchCheckRunnerInterface;
use Curentis\OpenFga\Client\ClientComponentFactoryInterface;
use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\ConsistencyBodyFactoryInterface;
use Curentis\OpenFga\Client\DefaultClientComponentFactory;
use Curentis\OpenFga\Client\DefaultConsistencyBodyFactory;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Client\OpenFgaClientFactoryInterface;
use Curentis\OpenFga\Client\OpenFgaClientInterface;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientTupleKey;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;
use Curentis\OpenFga\Client\WriteRunnerInterface;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Response;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Random\Randomizer;

final class ClientExtensibilityTest extends MockTransportTestCase
{
    public function testDefaultConsistencyBodyFactoryAppliesPreference(): void
    {
        $factory = new DefaultConsistencyBodyFactory();
        $body = $factory->listObjects(
            new \Curentis\OpenFga\Model\ListObjectsBody(relation: 'viewer', type: 'document', user: 'user:u'),
            \Curentis\OpenFga\Model\ConsistencyPreference::HIGHER_CONSISTENCY,
        );

        self::assertSame('HIGHER_CONSISTENCY', $body->consistency);
    }

    public function testComponentFactoryCanReplaceWriteRunner(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $factories = new \Nyholm\Psr7\Factory\Psr17Factory();

        $client = OpenFgaClientFactory::create(
            new ClientConfiguration(
                storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                httpClient: $mock,
                requestFactory: $factories,
                streamFactory: $factories,
                componentFactory: new TaggedWriteRunnerComponentFactory(),
            ),
        );

        $client->writeTuples([new ClientTupleKey('user:a', 'viewer', 'doc:1')]);
        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('custom-write', $request->getHeaderLine('X-Custom-Write'));
    }

    public function testClientFactoryOnConfigurationIsUsed(): void
    {
        $mock = new MockClient();
        $stub = $this->openFgaClient($mock);

        $client = OpenFgaClientFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                clientFactory: new StubOpenFgaClientFactory($stub),
            ),
        );

        self::assertSame($stub, $client);
    }
}

final class TaggedWriteRunnerComponentFactory implements ClientComponentFactoryInterface
{
    private readonly DefaultClientComponentFactory $defaults;

    public function __construct()
    {
        $this->defaults = new DefaultClientComponentFactory();
    }

    #[\Override]
    public function createBatchCheckRunner(OpenFgaApiInterface $api): BatchCheckRunnerInterface
    {
        return $this->defaults->createBatchCheckRunner($api);
    }

    #[\Override]
    public function createWriteRunner(OpenFgaApiInterface $api): WriteRunnerInterface
    {
        return new TaggedWriteRunner($api);
    }

    #[\Override]
    public function createConsistencyBodyFactory(): ConsistencyBodyFactoryInterface
    {
        return $this->defaults->createConsistencyBodyFactory();
    }
}

final class TaggedWriteRunner implements WriteRunnerInterface
{
    public function __construct(private readonly OpenFgaApiInterface $api) {}

    #[\Override]
    public function run(
        string $storeId,
        ClientWriteRequest $request,
        ?string $authorizationModelId,
        WriteOptions $writeOptions,
        array $headers,
    ): ClientWriteResponse {
        $body = \Curentis\OpenFga\Client\ClientRequestMapper::toWriteBody($request, $authorizationModelId, $writeOptions->conflict);
        $response = $this->api->write($storeId, $body, $headers + [
            'X-Custom-Write' => 'custom-write',
            'X-OpenFGA-Client-Method' => 'Write',
        ]);

        return new ClientWriteResponse($response);
    }
}

final class StubOpenFgaClientFactory implements OpenFgaClientFactoryInterface
{
    public function __construct(private readonly OpenFgaClientInterface $client) {}

    #[\Override]
    public function create(
        ClientConfiguration $configuration,
        ?ClockInterface $clock = null,
        ?Randomizer $randomizer = null,
    ): OpenFgaClientInterface {
        return $this->client;
    }
}
