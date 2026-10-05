<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class BatchCheckOptions
{
    public const int MAX_PARALLEL_REQUESTS = 10;

    public function __construct(
        public int $maxBatchSize = ClientConfiguration::DEFAULT_MAX_BATCH_SIZE,
        public int $maxParallelRequests = 1,
    ) {
        if ($maxBatchSize < 1 || $maxBatchSize > 50) {
            throw new FgaValidationException(sprintf('maxBatchSize must be between 1 and 50, got %d.', $maxBatchSize));
        }
        if ($maxParallelRequests < 1 || $maxParallelRequests > self::MAX_PARALLEL_REQUESTS) {
            throw new FgaValidationException(sprintf(
                'maxParallelRequests must be between 1 and %d, got %d.',
                self::MAX_PARALLEL_REQUESTS,
                $maxParallelRequests,
            ));
        }
    }
}
