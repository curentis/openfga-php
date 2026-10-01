<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

final readonly class ClientWriteTupleResult
{
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
        public string $operation,
        public bool $success,
        public ?\Throwable $error = null,
    ) {}
}
