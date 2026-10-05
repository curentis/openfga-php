<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

final class SystemSleeper implements Sleeper
{
    #[\Override]
    public function sleepMs(int $milliseconds): void
    {
        /** @infection-ignore-all */
        if ($milliseconds <= 0) {
            return;
        }

        /** @infection-ignore-all */
        usleep($milliseconds * 1000);
    }
}
