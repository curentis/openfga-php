<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

final readonly class ConflictOptions
{
    public function __construct(
        public ?string $onDuplicateWrites = null,
        public ?string $onMissingDeletes = null,
    ) {}
}
