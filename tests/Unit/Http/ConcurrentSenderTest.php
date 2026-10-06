<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\Adapter\ConcurrentSenderSelector;
use Curentis\OpenFga\Http\Adapter\GuzzleConcurrentSender;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ConcurrentSenderTest extends TestCase
{
    public function testSelectorUsesGuzzleOnlyForAGuzzleClient(): void
    {
        self::assertInstanceOf(
            GuzzleConcurrentSender::class,
            ConcurrentSenderSelector::select(new Client(['handler' => HandlerStack::create(new MockHandler())])),
        );
        self::assertNull(ConcurrentSenderSelector::select(new MockClient()));
    }

    public function testGuzzleSenderReturnsEveryStatusAndFailureInOrder(): void
    {
        $failure = new ConnectException('down', new GuzzleRequest('GET', 'http://localhost/c'));
        $handler = new MockHandler([
            new Response(200, [], 'a'),
            new Response(400, [], 'b'),
            $failure,
            new Response(503, [], 'd'),
            static fn(RequestInterface $request, array $options): PromiseInterface => Create::rejectionFor('not an exception'),
        ]);
        $sender = new GuzzleConcurrentSender(new Client(['handler' => HandlerStack::create($handler)]));

        $outcomes = $sender->send([
            new GuzzleRequest('GET', 'http://localhost/a'),
            new GuzzleRequest('GET', 'http://localhost/b'),
            new GuzzleRequest('GET', 'http://localhost/c'),
            new GuzzleRequest('GET', 'http://localhost/d'),
            new GuzzleRequest('GET', 'http://localhost/e'),
        ], 1);

        self::assertCount(5, $outcomes);
        self::assertInstanceOf(ResponseInterface::class, $outcomes[0]);
        self::assertSame('a', (string) $outcomes[0]->getBody());
        self::assertInstanceOf(ResponseInterface::class, $outcomes[1]);
        self::assertSame(400, $outcomes[1]->getStatusCode());
        self::assertSame($failure, $outcomes[2]);
        self::assertInstanceOf(ResponseInterface::class, $outcomes[3]);
        self::assertSame(503, $outcomes[3]->getStatusCode());
        self::assertInstanceOf(\RuntimeException::class, $outcomes[4]);
        self::assertSame('Parallel request was rejected without an exception.', $outcomes[4]->getMessage());
    }

    public function testGuzzleSenderHonoursTheConcurrencyLimit(): void
    {
        $inFlight = 0;
        $peak = 0;
        $handler = static function (RequestInterface $request, array $options) use (&$inFlight, &$peak): PromiseInterface {
            ++$inFlight;
            $peak = max($peak, $inFlight);

            return Create::promiseFor(new Response(200))->then(static function (ResponseInterface $response) use (&$inFlight): ResponseInterface {
                --$inFlight;

                return $response;
            });
        };
        $sender = new GuzzleConcurrentSender(new Client(['handler' => $handler]));
        $requests = [];
        for ($i = 0; $i < 6; ++$i) {
            $requests[] = new GuzzleRequest('GET', 'http://localhost/' . $i);
        }

        $sender->send($requests, 2);

        self::assertSame(2, $peak);
    }
}
