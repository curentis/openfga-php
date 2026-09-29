<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\TransportFactory;
use PHPUnit\Framework\TestCase;

final class TransportFactoryTest extends TestCase
{
    public function testDiscoverHttpClientUsesPsr18Discovery(): void
    {
        TransportFactory::discoverHttpClient();
        self::expectNotToPerformAssertions();
    }
}
