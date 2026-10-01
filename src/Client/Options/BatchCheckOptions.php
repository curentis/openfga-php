<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class BatchCheckOptions
{
    public function __construct(
        public int $maxBatchSize = ClientConfiguration::DEFAULT_MAX_BATCH_SIZE,
    ) {
        if ($maxBatchSize < 1 || $maxBatchSize > 50) {
            throw new FgaValidationException(sprintf('maxBatchSize must be between 1 and 50, got %d.', $maxBatchSize));
        }
    }
}
