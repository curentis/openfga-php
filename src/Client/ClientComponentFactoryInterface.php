<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Http\TransportInterface;

interface ClientComponentFactoryInterface
{
    public function createBatchCheckRunner(OpenFgaApiInterface $api, TransportInterface $transport): BatchCheckRunnerInterface;

    public function createWriteRunner(OpenFgaApiInterface $api): WriteRunnerInterface;

    public function createConsistencyBodyFactory(): ConsistencyBodyFactoryInterface;
}
