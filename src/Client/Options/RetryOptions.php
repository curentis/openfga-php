<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class RetryOptions
{
    public function __construct(
        public int $maxRetry = 3,
        public int $minWaitMs = 100,
        public int $maxElapsedMs = 10_000,
        public int $maxDelayMs = 5_000,
    ) {
        if ($maxRetry < 0 || $maxRetry > 15) {
            throw new FgaValidationException(sprintf('maxRetry must be between 0 and 15, got %d.', $maxRetry));
        }
        if ($minWaitMs < 1) {
            throw new FgaValidationException(sprintf('minWaitMs must be at least 1, got %d.', $minWaitMs));
        }
        if ($maxElapsedMs < 1) {
            throw new FgaValidationException(sprintf('maxElapsedMs must be at least 1, got %d.', $maxElapsedMs));
        }
        if ($maxDelayMs < 1) {
            throw new FgaValidationException(sprintf('maxDelayMs must be at least 1, got %d.', $maxDelayMs));
        }
    }
}
