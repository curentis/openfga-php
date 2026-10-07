<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Curentis\OpenFga\Http\Sleeper;

final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleptMilliseconds = [];

    public function __construct(private readonly ?ManualClock $clock = null) {}

    #[\Override]
    public function sleepMs(int $milliseconds): void
    {
        $this->sleptMilliseconds[] = $milliseconds;
        $this->clock?->advanceMs($milliseconds);
    }
}
