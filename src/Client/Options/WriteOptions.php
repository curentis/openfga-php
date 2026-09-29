<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class WriteOptions
{
    public function __construct(
        public TransactionOptions $transaction = new TransactionOptions(),
        public ConflictOptions $conflict = new ConflictOptions(),
        public int $maxPerChunk = 1,
    ) {
        if ($maxPerChunk < 1) {
            throw new FgaValidationException(sprintf('maxPerChunk must be at least 1, got %d.', $maxPerChunk));
        }
    }

}
