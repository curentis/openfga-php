<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaResponseDecodeException;
use Curentis\OpenFga\Http\AuthorizationHeaderProvider;
use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\Transport;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Version;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class TransportTest extends TestCase
{
    public function testBuildsRequestWithHeadersPathAndJsonBody(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"ok":true}'));
        $factories = new Psr17Factory();
        $transport = $this->transport($mock, new ApiToken('secret-token'));

        $transport->send(
            'POST',
            '/stores/{store_id}/check',
            ['store_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            [],
            ['tuple_key' => ['user' => 'u', 'relation' => 'r', 'object' => 'o']],
            ['X-Custom' => '1'],
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/stores/01ARZ3NDEKTSV4RRFFQ69G5FAV/check', $request->getUri()->getPath());
        self::assertSame('openfga-sdk php/' . Version::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        self::assertSame('yes', $request->getHeaderLine('X-Default'));
        self::assertSame('1', $request->getHeaderLine('X-Custom'));
        self::assertSame(
            '{"tuple_key":{"user":"u","relation":"r","object":"o"}}',
            (string) $request->getBody(),
        );
    }

    public function testSendJsonReturnsEmptyArrayForEmptyBody(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], ''));
        $transport = $this->transport($mock);

        self::assertSame([], $transport->sendJson('GET', '/stores'));
    }

    public function testSendJsonDecodesResponseBody(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[]}'));
        $transport = $this->transport($mock);

        self::assertSame(['stores' => []], $transport->sendJson('GET', '/stores'));
    }

    public function testQueryWithOnlyNullArrayItemsOmitsQueryString(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $transport = $this->transport($mock);

        $transport->send('GET', '/stores', [], ['types' => [null, null]]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('', $request->getUri()->getQuery());
    }

    public function testApiUrlTrailingSlashIsTrimmedBeforePathConcatenation(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $factories = new Psr17Factory();
        $retry = new RetryPolicy(
            0,
            100,
            new FakeSleeper(),
            new FrozenClock(new \DateTimeImmutable('@1700000000')),
            new Randomizer(new Mt19937(1)),
        );
        $transport = new Transport(
            'http://localhost:8080/',
            [],
            $mock,
            $factories,
            $factories,
            $factories,
            $retry,
            new AuthorizationHeaderProvider(new \Curentis\OpenFga\Credentials\NoCredentials()),
        );

        $transport->send('GET', '/stores');

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('http', $request->getUri()->getScheme());
        self::assertSame('localhost', $request->getUri()->getHost());
        self::assertSame(8080, $request->getUri()->getPort());
        self::assertSame('/stores', $request->getUri()->getPath());
    }

    public function testTrailingSlashIsRemovedFromTheUriPassedToTheFactory(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $factories = new Psr17Factory();
        $uris = new RecordingUriFactory();
        $retry = new RetryPolicy(
            0,
            100,
            new FakeSleeper(),
            new FrozenClock(new \DateTimeImmutable('@1700000000')),
            new Randomizer(new Mt19937(1)),
        );
        $transport = new Transport(
            'http://localhost:8080/',
            [],
            $mock,
            $factories,
            $factories,
            $uris,
            $retry,
            new AuthorizationHeaderProvider(new \Curentis\OpenFga\Credentials\NoCredentials()),
        );

        $transport->send('GET', '/stores');

        self::assertSame('http://localhost:8080/stores', $uris->created);
    }

    public function testQueryWithOnlyNullValuesOmitsQueryString(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $transport = $this->transport($mock);

        $transport->send('GET', '/stores', [], ['ignored' => null]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('', $request->getUri()->getQuery());
    }

    public function testQueryParametersSupportArraysAndSkipNulls(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $transport = $this->transport($mock);

        $transport->send('GET', '/stores', [], [
            'types' => ['a', null, 'b'],
            'continuation_token' => null,
            'page_size' => 10,
        ]);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('types=a&types=b&page_size=10', $request->getUri()->getQuery());
    }

    public function testRequestWithoutBodyOmitsContentType(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $transport = $this->transport($mock);

        $transport->send('GET', '/stores');

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('', $request->getHeaderLine('Content-Type'));
    }

    public function testEmptyJsonObjectEncodesAsObject(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{}'));
        $factories = new Psr17Factory();
        $transport = $this->transport($mock);

        $transport->send('POST', '/stores', [], [], []);

        $request = $mock->getLastRequest();
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('{}', (string) $request->getBody());
    }

    public function testRefreshesOauthCredentialsOnceAfter401(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(401, [], '{"code":"auth_expired","message":"expired"}'));
        $mock->addResponse(new Response(200, [], '{}'));
        $token = 'first';
        $invalidations = 0;
        $transport = $this->transportWithProvider($mock, new AuthorizationHeaderProvider(
            new ClientCredentials('client', 'secret', 'https://issuer.example', 'audience'),
            static function () use (&$token): string {
                return $token;
            },
            static function () use (&$token, &$invalidations): void {
                $token = 'second';
                ++$invalidations;
            },
        ));

        $response = $transport->send('GET', '/stores');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $invalidations);
        self::assertSame('Bearer first', $mock->getRequests()[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer second', $mock->getRequests()[1]->getHeaderLine('Authorization'));
    }

    public function testDoesNotRefreshTwiceWhen401Persists(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(401, [], '{"message":"expired"}'));
        $mock->addResponse(new Response(401, [], '{"message":"still expired"}'));
        $invalidations = 0;
        $transport = $this->transportWithProvider($mock, new AuthorizationHeaderProvider(
            new ClientCredentials('client', 'secret', 'https://issuer.example', 'audience'),
            static fn(): string => 'token',
            static function () use (&$invalidations): void {
                ++$invalidations;
            },
        ));

        $this->expectException(FgaApiAuthenticationException::class);
        try {
            $transport->send('GET', '/stores');
        } finally {
            self::assertSame(1, $invalidations);
            self::assertCount(2, $mock->getRequests());
        }
    }

    public function testDoesNotRefreshOn403(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(403, [], '{"message":"forbidden"}'));
        $invalidations = 0;
        $transport = $this->transportWithProvider($mock, new AuthorizationHeaderProvider(
            new ClientCredentials('client', 'secret', 'https://issuer.example', 'audience'),
            static fn(): string => 'token',
            static function () use (&$invalidations): void {
                ++$invalidations;
            },
        ));

        $this->expectException(FgaApiAuthenticationException::class);
        try {
            $transport->send('GET', '/stores');
        } finally {
            self::assertSame(0, $invalidations);
            self::assertCount(1, $mock->getRequests());
        }
    }

    public function testApiTokenCannotRefreshA401(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(401, [], '{"message":"expired"}'));
        $transport = $this->transport($mock, new ApiToken('static-token'));

        $this->expectException(FgaApiAuthenticationException::class);
        try {
            $transport->send('GET', '/stores');
        } finally {
            self::assertCount(1, $mock->getRequests());
        }
    }

    public function testSendJsonWrapsMalformedJson(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], 'not-json'));
        $transport = $this->transport($mock);

        try {
            $transport->sendJson('GET', '/stores');
            self::fail('Expected a decode exception');
        } catch (FgaResponseDecodeException $exception) {
            self::assertSame(0, $exception->getCode());
        }
    }

    public function testSendJsonRetriesServerErrorsByDefault(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{}'));
        $mock->addResponse(new Response(200, [], '{"ok":true}'));
        $transport = $this->transportWithRetry($mock, 1);

        $decoded = $transport->sendJson('GET', '/stores');

        self::assertSame(['ok' => true], $decoded);
        self::assertCount(2, $mock->getRequests());
    }

    public function testSendAllWithOneSlotStaysOnTheRetryingPath(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], 'ok'));
        $sender = new RecordingParallelSender();
        $transport = $this->transportWithSender($mock, $sender, 0);
        $request = (new Psr17Factory())->createRequest('GET', 'http://localhost:8080/stores');

        $responses = $transport->sendAll([$request], 1);

        self::assertSame(0, $sender->calls);
        self::assertSame('ok', (string) $responses[0]->getBody());
    }

    public function testSendAllRetriesWhenTheSenderCannotRunInParallel(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{}'));
        $mock->addResponse(new Response(200, [], 'ok'));
        $transport = $this->transportWithRetry($mock, 1);
        $request = (new Psr17Factory())->createRequest('GET', 'http://localhost:8080/stores');

        $responses = $transport->sendAll([$request], 4);

        self::assertSame('ok', (string) $responses[0]->getBody());
        self::assertCount(2, $mock->getRequests());
    }

    public function testSendAllUsesSequentialFallbackAndAllowsAnEmptyBatch(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], 'a'));
        $mock->addResponse(new Response(200, [], 'b'));
        $transport = $this->transport($mock);
        $factories = new Psr17Factory();

        $responses = $transport->sendAll([
            $factories->createRequest('GET', 'http://localhost:8080/stores'),
            $factories->createRequest('GET', 'http://localhost:8080/healthz'),
        ], 4);

        self::assertCount(2, $responses);
        self::assertFalse($transport->supportsParallel());
        self::assertSame([], $transport->sendAll([], 4));
    }

    private function transportWithProvider(MockClient $mock, AuthorizationHeaderProvider $provider): Transport
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
            ['X-Default' => 'yes'],
            $mock,
            $factories,
            $factories,
            $factories,
            $retry,
            $provider,
        );
    }

    private function transport(MockClient $mock, ?ApiToken $token = null): Transport
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
            ['X-Default' => 'yes'],
            $mock,
            $factories,
            $factories,
            $factories,
            $retry,
            new AuthorizationHeaderProvider($token ?? new \Curentis\OpenFga\Credentials\NoCredentials()),
        );
    }

    private function transportWithRetry(MockClient $mock, int $maxRetry): Transport
    {
        $factories = new Psr17Factory();

        return new Transport(
            'http://localhost:8080',
            [],
            $mock,
            $factories,
            $factories,
            $factories,
            new RetryPolicy(
                $maxRetry,
                1,
                new FakeSleeper(),
                new FrozenClock(new \DateTimeImmutable('@1700000000')),
                new Randomizer(new Mt19937(1)),
            ),
            new AuthorizationHeaderProvider(new \Curentis\OpenFga\Credentials\NoCredentials()),
        );
    }

    private function transportWithSender(MockClient $mock, ConcurrentSenderInterface $sender, int $maxRetry): Transport
    {
        $factories = new Psr17Factory();

        return new Transport(
            'http://localhost:8080',
            [],
            $mock,
            $factories,
            $factories,
            $factories,
            new RetryPolicy(
                $maxRetry,
                1,
                new FakeSleeper(),
                new FrozenClock(new \DateTimeImmutable('@1700000000')),
                new Randomizer(new Mt19937(1)),
            ),
            new AuthorizationHeaderProvider(new \Curentis\OpenFga\Credentials\NoCredentials()),
            $sender,
        );
    }
}

final class RecordingUriFactory implements UriFactoryInterface
{
    public string $created = '';

    public function __construct(private readonly UriFactoryInterface $inner = new Psr17Factory()) {}

    #[\Override]
    public function createUri(string $uri = ''): UriInterface
    {
        $this->created = $uri;

        return $this->inner->createUri($uri);
    }
}

final class RecordingParallelSender implements ConcurrentSenderInterface
{
    public int $calls = 0;

    #[\Override]
    public function supportsParallel(): bool
    {
        return true;
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<ResponseInterface>
     */
    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        $this->calls++;

        return [new Response(200, [], 'parallel')];
    }
}
