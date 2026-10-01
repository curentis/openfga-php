<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Psr\Clock\ClockInterface;
use Random\Randomizer;

interface OpenFgaClientFactoryInterface
{
    public function create(
        ClientConfiguration $configuration,
        ?ClockInterface $clock = null,
        ?Randomizer $randomizer = null,
    ): OpenFgaClientInterface;
}
