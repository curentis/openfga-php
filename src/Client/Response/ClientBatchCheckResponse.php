<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

final readonly class ClientBatchCheckResponse
{
    /**
     * @param list<ClientBatchCheckItemResult> $results match by `correlationId` or `check`; custom runners need not keep input order
     */
    public function __construct(
        public array $results,
    ) {}
}
