<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

final readonly class RequestOptions
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public ?string $storeId = null,
        public ?string $authorizationModelId = null,
        public array $headers = [],
        public ?RetryOptions $retry = null,
    ) {}
}
