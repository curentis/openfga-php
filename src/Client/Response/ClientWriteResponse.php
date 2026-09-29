<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

use Curentis\OpenFga\Model\WriteResponse;

final readonly class ClientWriteResponse
{
    /**
     * @param list<ClientWriteTupleResult> $tupleResults
     */
    public function __construct(
        public ?WriteResponse $response,
        public array $tupleResults = [],
    ) {}
}
