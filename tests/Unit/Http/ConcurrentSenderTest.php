<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Http\Adapter\ConcurrentSenderSelector;
use Curentis\OpenFga\Http\Adapter\GuzzleConcurrentSender;
use Curentis\OpenFga\Http\SequentialConcurrentSender;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class ConcurrentSenderTest extends TestCase
{
    public function testSequentialSenderPreservesOrder(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new \Nyholm\Psr7\Response(200, [], 'one'));
        $mock->addResponse(new \Nyholm\Psr7\Response(201, [], 'two'));
        $sender = new SequentialConcurrentSender($mock);

        $responses = $sender->send([
            new Request('GET', 'http://localhost/one'),
            new Request('GET', 'http://localhost/two'),
        ], 4);

        self::assertFalse($sender->supportsParallel());
        self::assertSame(200, $responses[0]->getStatusCode());
        self::assertSame(201, $responses[1]->getStatusCode());
    }

    public function testSelectorUsesGuzzleOnlyForAGuzzleClient(): void
    {
        self::assertInstanceOf(
            GuzzleConcurrentSender::class,
            ConcurrentSenderSelector::select(new Client(['handler' => HandlerStack::create(new MockHandler())])),
        );
        self::assertInstanceOf(
            SequentialConcurrentSender::class,
            ConcurrentSenderSelector::select(new MockClient()),
        );
    }

    public function testGuzzleSenderBatchesResponsesAndWrapsFailures(): void
    {
        $handler = new MockHandler([
            new Response(200, [], 'a'),
            new Response(204, [], 'b'),
        ]);
        $sender = new GuzzleConcurrentSender(new Client(['handler' => HandlerStack::create($handler)]));
        $responses = $sender->send([
            new GuzzleRequest('GET', 'http://localhost/a'),
            new GuzzleRequest('GET', 'http://localhost/b'),
        ], 0);

        self::assertTrue($sender->supportsParallel());
        self::assertSame('a', (string) $responses[0]->getBody());
        self::assertSame('b', (string) $responses[1]->getBody());

        $failing = new GuzzleConcurrentSender(new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new ConnectException('down', new GuzzleRequest('GET', 'http://localhost/c')),
            ])),
        ]));

        try {
            $failing->send([new GuzzleRequest('GET', 'http://localhost/c')], 2);
            self::fail('Expected a network exception');
        } catch (FgaNetworkException $exception) {
            self::assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }
    }
}
