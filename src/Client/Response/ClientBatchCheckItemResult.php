<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Model\BatchCheckSingleResult;

final readonly class ClientBatchCheckItemResult
{
    public function __construct(
        public string $correlationId,
        public ClientBatchCheckItem $check,
        public BatchCheckSingleResult $result,
    ) {}
}
