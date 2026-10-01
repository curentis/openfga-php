<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Request;

use Curentis\OpenFga\Model\ContextualTupleKeys;

final readonly class ClientBatchCheckItem
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public string $user,
        public string $relation,
        public string $object,
        public ?string $correlationId = null,
        public ?array $context = null,
        public ?ContextualTupleKeys $contextualTuples = null,
    ) {}
}
