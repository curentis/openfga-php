<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

final readonly class ClientListRelationsResponse
{
    /**
     * @param list<string> $relations subset of requested relations that are allowed
     */
    public function __construct(
        public array $relations,
    ) {}
}
