<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

final readonly class ClientBatchCheckResponse
{
    /**
     * @param list<ClientBatchCheckItemResult> $results in the same order as the input checks
     */
    public function __construct(
        public array $results,
    ) {}
}
