<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class ManualClock implements ClockInterface
{
    public function __construct(private int $nowMs = 1_700_000_000_000) {}

    public function advanceMs(int $milliseconds): void
    {
        $this->nowMs += $milliseconds;
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        $now = DateTimeImmutable::createFromFormat(
            'U.v',
            sprintf('%d.%03d', intdiv($this->nowMs, 1000), $this->nowMs % 1000),
        );
        \assert($now instanceof DateTimeImmutable);

        return $now;
    }
}
