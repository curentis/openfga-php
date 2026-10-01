<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class OpenFgaClientFactoryTest extends TestCase
{
    public function testCreateWiresClientCredentialsTokenProvider(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"oauth-token","expires_in":3600}'));
        $mock->addResponse(new Response(200, [], '{"stores":[],"continuation_token":""}'));

        $factories = new Psr17Factory();
        $configuration = new ClientConfiguration(
            storeId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            credentials: new ClientCredentials('client', 'secret', 'issuer.example', 'audience'),
            httpClient: $mock,
            requestFactory: $factories,
            streamFactory: $factories,
        );

        $clock = new FrozenClock(new \DateTimeImmutable('@1700000000'));
        $client = OpenFgaClientFactory::create($configuration, $clock, new Randomizer(new Mt19937(1)));

        self::assertSame([], $client->listStores()->stores);
        self::assertCount(2, $mock->getRequests());
        self::assertStringContainsString('/oauth/token', (string) $mock->getRequests()[0]->getUri());
        self::assertSame('Bearer oauth-token', $mock->getRequests()[1]->getHeaderLine('Authorization'));
    }
}
