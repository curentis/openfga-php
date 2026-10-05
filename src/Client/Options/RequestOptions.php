<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

use Curentis\OpenFga\Client\Ulid;

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
    ) {
        if ($storeId !== null && $storeId !== '') {
            Ulid::assert($storeId, 'storeId');
        }
        if ($authorizationModelId !== null && $authorizationModelId !== '') {
            Ulid::assert($authorizationModelId, 'authorizationModelId');
        }
    }
}
