<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Credentials\NoCredentials;
use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaResponseDecodeException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\AuthorizationHeaderProvider;
use Curentis\OpenFga\Http\ConcurrentSenderInterface;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\Transport;
use Curentis\OpenFga\Http\TransportCall;
use Curentis\OpenFga\Observability\RequestFinished;
use Curentis\OpenFga\Observability\RequestOutcome;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Tests\Support\ManualClock;
use Curentis\OpenFga\Tests\Support\RecordingDispatcher;
use Curentis\OpenFga\Tests\Support\TestNetworkException;
use Curentis\OpenFga\Version;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testSendJsonAllRunsSequentiallyWithoutAConcurrentSender(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{}'));
        $mock->addResponse(new Response(200, [], '{"n":1}'));
        $mock->addResponse(new Response(200, [], '{"n":2}'));
        $transport = $this->transportWithRetry($mock, 1);

        $decoded = $transport->sendJsonAll([new TransportCall('GET', '/stores'), new TransportCall('GET', '/stores')], 4);

        self::assertSame([['n' => 1], ['n' => 2]], $decoded);
        self::assertCount(3, $mock->getRequests());
    }

    public function testSendJsonAllWithOneSlotSkipsTheConcurrentSender(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"ok":true}'));
        $sender = new RecordingParallelSender([new Response(200, [], '{"parallel":true}')]);
        $transport = $this->transportWithSender($mock, $sender);

        self::assertSame([['ok' => true]], $transport->sendJsonAll([new TransportCall('GET', '/stores')], 1));
        self::assertSame([], $sender->batches);
    }

    public function testSendJsonAllDecodesConcurrentSuccessesAndReportsThem(): void
    {
        $dispatcher = new RecordingDispatcher();
        $sender = new RecordingParallelSender([
            new Response(200, [], '{"a":1}'),
            new Response(200, [], ''),
        ]);
        $transport = $this->transportWithSender(new MockClient(), $sender, telemetry: new SdkTelemetry(dispatcher: $dispatcher));

        $decoded = $transport->sendJsonAll([
            new TransportCall('POST', '/stores/{store_id}/batch-check', ['store_id' => 's1'], [], ['checks' => []], ['X-Test' => '1'], 's1'),
            new TransportCall('GET', '/stores'),
        ], 3);

        self::assertSame([['a' => 1], []], $decoded);
        self::assertSame([3], $sender->parallelism);
        self::assertSame('/stores/s1/batch-check', $sender->batches[0][0]->getUri()->getPath());
        self::assertSame('1', $sender->batches[0][0]->getHeaderLine('X-Test'));
        self::assertSame('{"checks":[]}', (string) $sender->batches[0][0]->getBody());
        $finished = $dispatcher->events[0];
        self::assertInstanceOf(RequestFinished::class, $finished);
        self::assertSame('/stores/{store_id}/batch-check', $finished->route);
        self::assertSame('s1', $finished->storeId);
        self::assertSame(200, $finished->statusCode);
        self::assertSame(1, $finished->attempts);
        self::assertSame(RequestOutcome::Success, $finished->outcome);
    }

    public function testConcurrentValidationErrorIsMappedWithoutResending(): void
    {
        $mock = new MockClient();
        $dispatcher = new RecordingDispatcher();
        $sender = new RecordingParallelSender([
            new Response(400, [], '{"code":"validation_error","message":"type not found"}'),
        ]);
        $transport = $this->transportWithSender($mock, $sender, telemetry: new SdkTelemetry(dispatcher: $dispatcher));

        try {
            $transport->sendJsonAll([new TransportCall('POST', '/stores/{store_id}/batch-check', ['store_id' => 's1'], storeId: 's1')], 2);
            self::fail('Expected a validation exception');
        } catch (FgaApiValidationException $exception) {
            self::assertSame('POST', $exception->method);
            self::assertSame('/stores/s1/batch-check', $exception->endpoint);
            self::assertSame('s1', $exception->storeId);
            self::assertSame('type not found', $exception->apiErrorMessage);
        }
        self::assertCount(0, $mock->getRequests());
        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(400, $dispatcher->events[0]->statusCode);
        self::assertSame(1, $dispatcher->events[0]->attempts);
        self::assertSame(RequestOutcome::HttpError, $dispatcher->events[0]->outcome);
    }

    public function testConcurrentRedirectStatusIsNotTreatedAsSuccess(): void
    {
        $sender = new RecordingParallelSender([new Response(300, [], '{}')]);
        $transport = $this->transportWithSender(new MockClient(), $sender);

        try {
            $transport->sendJsonAll([new TransportCall('GET', '/stores')], 2);
            self::fail('Expected an API exception');
        } catch (FgaApiException $exception) {
            self::assertSame(300, $exception->statusCode);
        }
    }

    public function testConcurrentConflictIsMappedWithoutResending(): void
    {
        $sender = new RecordingParallelSender([new Response(409, ['Retry-After' => '2'], '{}')]);
        $transport = $this->transportWithSender(new MockClient(), $sender);

        try {
            $transport->sendJsonAll([new TransportCall('POST', '/stores', idempotent: false)], 2);
            self::fail('Expected an API exception');
        } catch (FgaApiException $exception) {
            self::assertSame(409, $exception->statusCode);
        }
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function resendableStatusProvider(): iterable
    {
        yield '401' => [401];
        yield '429' => [429];
        yield '500' => [500];
        yield '503' => [503];
    }

    #[DataProvider('resendableStatusProvider')]
    public function testConcurrentRetryableStatusIsResentThroughTheRetryPath(int $status): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"ok":true}'));
        $sender = new RecordingParallelSender([new Response($status, [], '{}')]);
        $transport = $this->transportWithSender($mock, $sender);

        $decoded = $transport->sendJsonAll([new TransportCall('POST', '/stores/{store_id}/batch-check', ['store_id' => 's1'])], 2);

        self::assertSame([['ok' => true]], $decoded);
        self::assertSame('/stores/s1/batch-check', $this->lastPath($mock));
    }

    public function testConcurrentServerErrorOnANonIdempotentCallIsNotResent(): void
    {
        $mock = new MockClient();
        $transport = $this->transportWithSender($mock, new RecordingParallelSender([new Response(500, [], '{}')]));

        $this->expectException(FgaApiInternalException::class);
        try {
            $transport->sendJsonAll([new TransportCall('POST', '/stores', idempotent: false)], 2);
        } finally {
            self::assertCount(0, $mock->getRequests());
        }
    }

    public function testConcurrentRateLimitOnANonIdempotentCallIsResent(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"id":"x"}'));
        $transport = $this->transportWithSender($mock, new RecordingParallelSender([new Response(429, [], '{}')]));

        self::assertSame([['id' => 'x']], $transport->sendJsonAll([new TransportCall('POST', '/stores', idempotent: false)], 2));
    }

    public function testConcurrentTransportFailureIsResentForIdempotentCalls(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"ok":true}'));
        $transport = $this->transportWithSender($mock, new RecordingParallelSender([new TestNetworkException('reset')]));

        self::assertSame([['ok' => true]], $transport->sendJsonAll([new TransportCall('GET', '/stores')], 2));
        self::assertCount(1, $mock->getRequests());
    }

    public function testMissingConcurrentOutcomeIsResent(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"second":true}'));
        $transport = $this->transportWithSender($mock, new RecordingParallelSender([new Response(200, [], '{"first":true}')]));

        $decoded = $transport->sendJsonAll([new TransportCall('GET', '/stores'), new TransportCall('GET', '/healthz')], 2);

        self::assertSame([['first' => true], ['second' => true]], $decoded);
        self::assertSame('/healthz', $this->lastPath($mock));
    }

    public function testConcurrentTransportFailureOnANonIdempotentCallIsWrapped(): void
    {
        $mock = new MockClient();
        $dispatcher = new RecordingDispatcher();
        $root = new TestNetworkException('reset');
        $transport = $this->transportWithSender(
            $mock,
            new RecordingParallelSender([$root]),
            telemetry: new SdkTelemetry(dispatcher: $dispatcher),
        );

        try {
            $transport->sendJsonAll([new TransportCall('POST', '/stores', idempotent: false)], 2);
            self::fail('Expected a network exception');
        } catch (FgaNetworkException $exception) {
            self::assertSame($root, $exception->getPrevious());
            self::assertSame('OpenFGA API request failed (POST /stores).', $exception->getMessage());
            self::assertSame('POST', $exception->method);
            self::assertSame('/stores', $exception->endpoint);
        }
        self::assertCount(0, $mock->getRequests());
        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertNull($dispatcher->events[0]->statusCode);
        self::assertSame(1, $dispatcher->events[0]->attempts);
        self::assertSame(RequestOutcome::NetworkError, $dispatcher->events[0]->outcome);
    }

    public function testConcurrentNonJsonSuccessRaisesADecodeException(): void
    {
        $transport = $this->transportWithSender(new MockClient(), new RecordingParallelSender([new Response(200, [], '<html>')]));

        $this->expectException(FgaResponseDecodeException::class);
        $this->expectExceptionMessage('OpenFGA API returned a non-JSON response (GET /stores).');
        $transport->sendJsonAll([new TransportCall('GET', '/stores')], 2);
    }

    public function testConcurrentDurationCoversTheBatch(): void
    {
        $clock = new ManualClock();
        $dispatcher = new RecordingDispatcher();
        $sender = new RecordingParallelSender([new Response(200, [], '{}')], $clock, 25);
        $transport = $this->transportWithSender(
            new MockClient(),
            $sender,
            telemetry: new SdkTelemetry(dispatcher: $dispatcher),
            clock: $clock,
        );

        $transport->sendJsonAll([new TransportCall('GET', '/stores')], 2);

        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(25, $dispatcher->events[0]->durationMs);
    }

    public function testErrorEndpointIncludesTheApiUrlBasePath(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(404, [], '{}'));
        $factories = new Psr17Factory();
        $transport = new Transport(
            'http://localhost:8080/fga/',
            [],
            $mock,
            $factories,
            $factories,
            $factories,
            new RetryPolicy(0, 1, new FakeSleeper(), new FrozenClock(new \DateTimeImmutable('@1700000000')), new Randomizer(new Mt19937(1))),
            new AuthorizationHeaderProvider(new NoCredentials()),
        );

        try {
            $transport->send('GET', '/stores/{store_id}', ['store_id' => 's1']);
            self::fail('Expected a not-found exception');
        } catch (FgaApiNotFoundException $exception) {
            self::assertSame('/fga/stores/s1', $exception->endpoint);
        }
        self::assertSame('/fga/stores/s1', $this->lastPath($mock));
    }

    public function testUnencodableBodyIsAValidationError(): void
    {
        $mock = new MockClient();
        $transport = $this->transport($mock);

        $this->expectException(FgaValidationException::class);
        try {
            $transport->send('POST', '/stores', [], [], ['name' => "bad\xB1"]);
        } finally {
            self::assertCount(0, $mock->getRequests());
        }
    }

    private function lastPath(MockClient $mock): string
    {
        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request->getUri()->getPath();
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

    private function transportWithSender(
        MockClient $mock,
        ConcurrentSenderInterface $sender,
        int $maxRetry = 0,
        ?SdkTelemetry $telemetry = null,
        ?ManualClock $clock = null,
    ): Transport {
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
            new AuthorizationHeaderProvider(new NoCredentials()),
            $sender,
            telemetry: $telemetry ?? new SdkTelemetry(),
            clock: $clock ?? new ManualClock(),
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
    /** @var list<int> */
    public array $parallelism = [];

    /** @var list<list<RequestInterface>> */
    public array $batches = [];

    /**
     * @param list<ResponseInterface|\Throwable> $outcomes
     */
    public function __construct(
        private readonly array $outcomes,
        private readonly ?ManualClock $clock = null,
        private readonly int $elapsedMs = 0,
    ) {}

    #[\Override]
    public function send(array $requests, int $maxParallel): array
    {
        $this->parallelism[] = $maxParallel;
        $this->batches[] = $requests;
        $this->clock?->advanceMs($this->elapsedMs);

        return $this->outcomes;
    }
}
