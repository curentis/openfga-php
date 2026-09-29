<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

use Curentis\OpenFga\Model\BatchCheckSingleResult;

final readonly class ClientBatchCheckResponse
{
    /**
     * @param list<BatchCheckSingleResult> $results in the same order as the input checks
     */
    public function __construct(
        public array $results,
    ) {}
}
