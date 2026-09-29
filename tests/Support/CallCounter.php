<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

/** @internal */
final class CallCounter
{
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }
}
