<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;

interface ClientComponentFactoryInterface
{
    public function createBatchCheckRunner(OpenFgaApiInterface $api): BatchCheckRunnerInterface;

    public function createWriteRunner(OpenFgaApiInterface $api): WriteRunnerInterface;

    public function createConsistencyBodyFactory(): ConsistencyBodyFactoryInterface;
}
