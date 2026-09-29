<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final class NativeClock implements ClockInterface
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
