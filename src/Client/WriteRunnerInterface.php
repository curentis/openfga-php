<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Client\Options\WriteOptions;
use Curentis\OpenFga\Client\Request\ClientWriteRequest;
use Curentis\OpenFga\Client\Response\ClientWriteResponse;

interface WriteRunnerInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function run(
        string $storeId,
        ClientWriteRequest $request,
        ?string $authorizationModelId,
        WriteOptions $writeOptions,
        array $headers,
        ?RetryOptions $retry = null,
    ): ClientWriteResponse;
}
