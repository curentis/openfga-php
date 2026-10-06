<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string|Stringable, 2: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, $message, $context];
    }
}
