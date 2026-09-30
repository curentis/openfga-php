<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class OpenFgaClientFactoryNoAuthTest extends TestCase
{
    public function testCreateWithoutOAuthCredentialsSkipsTokenExchange(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));
        $factories = new Psr17Factory();

        $client = OpenFgaClientFactory::create(
            new ClientConfiguration(
                storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                httpClient: $mock,
                requestFactory: $factories,
                streamFactory: $factories,
            ),
            new FrozenClock(new \DateTimeImmutable('@1700000000')),
            new Randomizer(new Mt19937(1)),
        );

        self::assertSame([], $client->listStores()->stores);
        self::assertCount(1, $mock->getRequests());
        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testCreateUsesDefaultClockAndRandomizerWhenOmitted(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));
        $factories = new Psr17Factory();

        $client = OpenFgaClientFactory::create(
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
}
