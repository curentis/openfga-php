<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $frozen) {}

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return $this->frozen;
    }
}
