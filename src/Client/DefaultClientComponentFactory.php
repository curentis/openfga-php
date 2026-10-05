<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;
use Curentis\OpenFga\Http\TransportInterface;

final class DefaultClientComponentFactory implements ClientComponentFactoryInterface
{
    #[\Override]
    public function createBatchCheckRunner(OpenFgaApiInterface $api, TransportInterface $transport): BatchCheckRunnerInterface
    {
        return new BatchCheckRunner($api, $transport);
    }

    #[\Override]
    public function createWriteRunner(OpenFgaApiInterface $api): WriteRunnerInterface
    {
        return new WriteRunner($api);
    }

    #[\Override]
    public function createConsistencyBodyFactory(): ConsistencyBodyFactoryInterface
    {
        return new DefaultConsistencyBodyFactory();
    }
}
