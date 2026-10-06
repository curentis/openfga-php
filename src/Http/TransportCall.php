<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

final readonly class TransportCall
{
    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $headers
     */
    public function __construct(
        public string $method,
        public string $pathTemplate,
        public array $pathParams = [],
        public array $query = [],
        public mixed $body = null,
        public array $headers = [],
        public ?string $storeId = null,
        public bool $idempotent = true,
    ) {}
}
