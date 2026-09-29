<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

final readonly class PaginationOptions
{
    public function __construct(
        public ?int $pageSize = null,
        public ?string $continuationToken = null,
    ) {}
}
