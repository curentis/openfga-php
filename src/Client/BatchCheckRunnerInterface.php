<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Model\ConsistencyPreference;

interface BatchCheckRunnerInterface
{
    /**
     * Result order is not part of the contract. Callers must match results by `correlationId` or `check`.
     *
     * @param list<ClientBatchCheckItem> $checks
     * @param array<string, string>    $headers
     */
    public function run(
        string $storeId,
        array $checks,
        BatchCheckOptions $options,
        ?string $authorizationModelId,
        ?ConsistencyPreference $consistency,
        array $headers,
        ?RetryOptions $retry = null,
    ): ClientBatchCheckResponse;
}
