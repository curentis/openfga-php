<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Curentis\OpenFga\Http\Sleeper;

final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    public array $sleptMilliseconds = [];

    #[\Override]
    public function sleepMs(int $milliseconds): void
    {
        $this->sleptMilliseconds[] = $milliseconds;
    }
}
