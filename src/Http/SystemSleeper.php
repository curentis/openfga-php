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
        /** @infection-ignore-all */
        if ($milliseconds <= 0) {
            return;
        }

        usleep(self::microseconds($milliseconds));
    }

    public static function microseconds(int $milliseconds): int
    {
        return $milliseconds * 1000;
    }
}
