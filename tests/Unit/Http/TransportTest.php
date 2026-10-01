<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Http\AuthorizationHeaderProvider;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\Transport;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Version;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
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
