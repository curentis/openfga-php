<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\DefaultOpenFgaClientFactory;
use Curentis\OpenFga\Client\OpenFgaClient;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Tests\Support\MockTransportTestCase;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

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

    public function testCreateUsesExplicitRequestFactoryWhenProvided(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                requestFactory: new MarkingRequestFactory(),
                streamFactory: new Psr17Factory(),
            ),
        );

        $client->listStores();
        self::assertSame('custom', $mock->getRequests()[0]->getHeaderLine('X-Request-Factory'));
    }

    public function testCreateUsesExplicitStreamFactoryWhenOnlyStreamFactoryProvided(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{}'));

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                requestFactory: new Psr17Factory(),
                streamFactory: new MarkingStreamFactory(),
            ),
        );

        $client->write(
            new \Curentis\OpenFga\Client\Request\ClientWriteRequest(
                writes: [new \Curentis\OpenFga\Client\Request\ClientTupleKey('user:a', 'viewer', 'doc:1')],
            ),
        );

        self::assertStringStartsWith('MARKER:', (string) $mock->getRequests()[0]->getBody());
    }

    public function testCreateUsesExplicitHttpFactoriesForTokenProvider(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'));
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                requestFactory: new MarkingRequestFactory(),
                streamFactory: new MarkingStreamFactory(),
                credentials: new ClientCredentials('client', 'secret', 'issuer.example', 'audience'),
            ),
        );

        $client->listStores();
        self::assertSame('custom', $mock->getRequests()[0]->getHeaderLine('X-Request-Factory'));
        self::assertStringStartsWith('MARKER:', (string) $mock->getRequests()[0]->getBody());
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

    public function testCreateEncryptsCachedTokensWhenAKeyIsConfigured(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"access_token":"tok","expires_in":3600}'));
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));
        $factories = new Psr17Factory();
        $logger = new \Curentis\OpenFga\Tests\Support\RecordingLogger();

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                requestFactory: $factories,
                streamFactory: $factories,
                uriFactory: $factories,
                credentials: new ClientCredentials('client', 'secret', 'issuer.example', 'audience'),
                tokenCache: new \Curentis\OpenFga\Tests\Support\SimpleArrayCache(),
                tokenCacheKey: random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES),
                telemetry: new \Curentis\OpenFga\Observability\SdkTelemetry($logger),
            ),
            new \Curentis\OpenFga\Tests\Support\FrozenClock(new \DateTimeImmutable('@1700000000')),
            new \Random\Randomizer(new \Random\Engine\Mt19937(1)),
        );

        $client->listStores();
        $client->listStores();

        self::assertCount(3, $mock->getRequests());
        $messages = array_map(static fn(array $record): string => (string) $record[1], $logger->records);
        self::assertContains('openfga.token_refresh', $messages);
    }

    public function testCreateRefreshesTheCachedTokenAfterUnauthorized(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"access_token":"one","expires_in":3600}'));
        $mock->addResponse(new \Nyholm\Psr7\Response(401, [], '{"message":"expired"}'));
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"access_token":"two","expires_in":3600}'));
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));
        $factories = new Psr17Factory();

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                requestFactory: $factories,
                streamFactory: $factories,
                uriFactory: $factories,
                credentials: new ClientCredentials('client', 'secret', 'issuer.example', 'audience'),
                tokenCache: new \Curentis\OpenFga\Tests\Support\SimpleArrayCache(),
            ),
            new \Curentis\OpenFga\Tests\Support\FrozenClock(new \DateTimeImmutable('@1700000000')),
            new \Random\Randomizer(new \Random\Engine\Mt19937(1)),
        );

        self::assertSame([], $client->listStores()->stores);
        self::assertCount(4, $mock->getRequests());
    }

    public function testCreateUsesTheConfiguredUriFactory(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], '{"stores":[],"continuation_token":""}'));
        $factories = new Psr17Factory();
        $uriFactory = new CountingUriFactory();

        $client = (new DefaultOpenFgaClientFactory())->create(
            new ClientConfiguration(
                storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                httpClient: $mock,
                requestFactory: $factories,
                streamFactory: $factories,
                uriFactory: $uriFactory,
            ),
        );

        self::assertSame([], $client->listStores()->stores);
        self::assertGreaterThan(0, $uriFactory->calls);
    }
}

final class MarkingRequestFactory implements RequestFactoryInterface
{
    public function __construct(private readonly RequestFactoryInterface $inner = new Psr17Factory()) {}

    #[\Override]
    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->inner->createRequest($method, $uri)->withHeader('X-Request-Factory', 'custom');
    }
}

final class MarkingStreamFactory implements StreamFactoryInterface
{
    public function __construct(private readonly StreamFactoryInterface $inner = new Psr17Factory()) {}

    #[\Override]
    public function createStream(string $content = ''): StreamInterface
    {
        return $this->inner->createStream('MARKER:' . $content);
    }

    #[\Override]
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->inner->createStreamFromFile($filename, $mode);
    }

    #[\Override]
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->inner->createStreamFromResource($resource);
    }
}

final class CountingUriFactory implements UriFactoryInterface
{
    public int $calls = 0;

    public function __construct(private readonly UriFactoryInterface $inner = new Psr17Factory()) {}

    #[\Override]
    public function createUri(string $uri = ''): UriInterface
    {
        ++$this->calls;

        return $this->inner->createUri($uri);
    }
}
