<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Options\BatchCheckOptions;
use Curentis\OpenFga\Client\Request\ClientBatchCheckItem;
use Curentis\OpenFga\Client\Response\ClientBatchCheckResponse;
use Curentis\OpenFga\Model\ConsistencyPreference;

interface BatchCheckRunnerInterface
{
    /**
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
    ): ClientBatchCheckResponse;
}
