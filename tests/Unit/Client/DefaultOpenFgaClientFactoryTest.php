<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\DefaultOpenFgaClientFactory;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class DefaultOpenFgaClientFactoryTest extends TestCase
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
}
