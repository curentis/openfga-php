<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Request;

final readonly class ClientTupleKey
{
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
        public ?string $conditionName = null,
        /** @var array<string, mixed>|null */
        public ?array $conditionContext = null,
    ) {}
}
