<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Http\TransportFactory;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class TransportFactoryTest extends TestCase
{
    public function testDiscoverHttpClientUsesPsr18Discovery(): void
    {
        TransportFactory::discoverHttpClient();
        self::expectNotToPerformAssertions();
    }

    public function testCreateUsesConfiguredRequestFactory(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                requestFactory: new MarkingRequestFactory(),
            ),
        );

        $transport->send('GET', '/stores');

        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('custom', $request->getHeaderLine('X-Request-Factory'));
    }

    public function testCreateUsesInjectedSleeperClockAndRandomizer(): void
    {
        $mock = new MockClient();
        $retryAt = gmdate('D, d M Y H:i:s', 1_700_000_000 + 5) . ' GMT';
        $mock->addResponse(new Response(503, ['Retry-After' => $retryAt], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $sleeper = new FakeSleeper();
        $clock = new FrozenClock(new \DateTimeImmutable('@1700000000'));

        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                retry: new \Curentis\OpenFga\Client\Options\RetryOptions(maxRetry: 1, minWaitMs: 100),
            ),
            $clock,
            $sleeper,
            new Randomizer(new Mt19937(99)),
        );

        $transport->send('GET', '/stores');
        self::assertSame([5000], $sleeper->sleptMilliseconds);
    }

    public function testCreateUsesInjectedRandomizerForBackoff(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(503, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $sleeper = new FakeSleeper();
        $expectedDelay = (new Randomizer(new Mt19937(99)))->getInt(100, 200);

        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                retry: new \Curentis\OpenFga\Client\Options\RetryOptions(maxRetry: 1, minWaitMs: 100),
            ),
            null,
            $sleeper,
            new Randomizer(new Mt19937(99)),
        );

        $transport->send('GET', '/stores');
        self::assertSame([$expectedDelay], $sleeper->sleptMilliseconds);
    }

    public function testCreateFallsBackToDefaultSleeperClockAndRandomizer(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));

        $transport = TransportFactory::create(
            new ClientConfiguration(apiUrl: 'http://localhost:8080', httpClient: $mock),
        );

        $transport->send('GET', '/stores');
        self::assertCount(1, $mock->getRequests());
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
