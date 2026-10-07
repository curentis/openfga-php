<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\TransportCall;
use Curentis\OpenFga\Http\TransportFactory;
use Curentis\OpenFga\Observability\RequestFinished;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Tests\Support\ManualClock;
use Curentis\OpenFga\Tests\Support\RecordingDispatcher;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class TransportFactoryTest extends TestCase
{
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

    public function testCreateUsesTheConfiguredUriFactory(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $uriFactory = new MarkingUriFactory();
        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                uriFactory: $uriFactory,
            ),
        );

        $transport->send('GET', '/stores');
        self::assertGreaterThan(0, $uriFactory->calls);
    }

    public function testCreateBuildsARandomizerWhenRetryNeedsOne(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(503, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $sleeper = new FakeSleeper();
        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                retry: new \Curentis\OpenFga\Client\Options\RetryOptions(maxRetry: 1, minWaitMs: 100),
            ),
            null,
            $sleeper,
        );

        $transport->send('GET', '/stores');
        self::assertCount(1, $sleeper->sleptMilliseconds);
    }

    public function testCreatePassesTheConcurrentSenderTelemetryAndClock(): void
    {
        $mock = new MockClient();
        $clock = new ManualClock();
        $dispatcher = new RecordingDispatcher();
        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                telemetry: new SdkTelemetry(dispatcher: $dispatcher),
            ),
            $clock,
            concurrentSender: new FlagConcurrentSender($clock),
        );

        self::assertSame([['parallel' => true]], $transport->sendJsonAll([new TransportCall('GET', '/stores')], 2));
        self::assertCount(0, $mock->getRequests());
        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(30, $dispatcher->events[0]->durationMs);
    }

    public function testCreateKeepsAnInjectedRetryPolicy(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(503, [], '{}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $policy = new RetryPolicy(
            0,
            1,
            new FakeSleeper(),
            new FrozenClock(new \DateTimeImmutable('@1700000000')),
            new Randomizer(new Mt19937(1)),
        );
        $transport = TransportFactory::create(
            new ClientConfiguration(
                apiUrl: 'http://localhost:8080',
                httpClient: $mock,
                retry: new RetryOptions(maxRetry: 3, minWaitMs: 100),
            ),
            retryPolicy: $policy,
        );

        try {
            $transport->send('GET', '/stores');
            self::fail('Expected the injected policy to stop after one attempt');
        } catch (FgaApiInternalException) {
            self::assertCount(1, $mock->getRequests());
        }
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

final class MarkingUriFactory implements UriFactoryInterface
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

final class FlagConcurrentSender implements ConcurrentSenderInterface
{
    public function __construct(private readonly ManualClock $clock) {}

    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        $this->clock->advanceMs(30);

        return [new Response(200, [], '{"parallel":true}')];
    }
}
