<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Curentis\OpenFga\Api\OpenFgaApi;
use Curentis\OpenFga\Credentials\NoCredentials;
use Curentis\OpenFga\Http\AuthorizationHeaderProvider;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Http\Transport;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

abstract class MockTransportTestCase extends TestCase
{
    protected function openFgaApi(MockClient $mock): OpenFgaApi
    {
        return new OpenFgaApi($this->transport($mock));
    }

    protected function transport(MockClient $mock): Transport
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
            [],
            $mock,
            $factories,
            $factories,
            $factories,
            $retry,
            new AuthorizationHeaderProvider(new NoCredentials()),
        );
    }
}
