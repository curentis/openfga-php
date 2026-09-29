<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

/**
 * @internal
 */
interface Sleeper
{
    public function sleepMs(int $milliseconds): void;
}
