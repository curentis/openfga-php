<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client;

use Curentis\OpenFga\Api\OpenFgaApiInterface;

final class DefaultClientComponentFactory implements ClientComponentFactoryInterface
{
    #[\Override]
    public function createBatchCheckRunner(OpenFgaApiInterface $api): BatchCheckRunnerInterface
    {
        return new BatchCheckRunner($api);
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
