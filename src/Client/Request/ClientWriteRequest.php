<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Request;

final readonly class ClientWriteRequest
{
    /**
     * @param list<ClientTupleKey>                  $writes
     * @param list<ClientTupleKeyWithoutCondition> $deletes
     */
    public function __construct(
        public array $writes = [],
        public array $deletes = [],
    ) {}
}
