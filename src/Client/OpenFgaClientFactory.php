<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Psr\Clock\ClockInterface;
use Random\Randomizer;

final class OpenFgaClientFactory
{
    public static function create(
        ClientConfiguration $configuration,
        ?ClockInterface $clock = null,
        ?Randomizer $randomizer = null,
    ): OpenFgaClientInterface {
        return (new DefaultOpenFgaClientFactory())->create($configuration, $clock, $randomizer);
    }
}
