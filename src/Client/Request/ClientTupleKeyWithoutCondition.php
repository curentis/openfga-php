<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Request;

final readonly class ClientTupleKeyWithoutCondition
{
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
    ) {}
}
