<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Observability;

final class RetryScheduled
{
    public function __construct(
        public readonly string $method,
        public readonly string $endpoint,
        public readonly int $attempt,
        public readonly int $delayMs,
    ) {}
}
