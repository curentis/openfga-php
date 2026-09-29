<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

/**
 * @internal
 */
final class SystemSleeper implements Sleeper
{
    #[\Override]
    public function sleepMs(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
