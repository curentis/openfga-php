<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

final readonly class TransactionOptions
{
    public function __construct(
        public bool $disable = false,
    ) {}
}
