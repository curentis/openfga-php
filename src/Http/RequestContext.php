<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

/**
 * Describes one logical API call for retries, error mapping and telemetry.
 */
final readonly class RequestContext
{
    /** Low-cardinality path template such as `/stores/{store_id}/check`. */
    public string $route;

    /**
     * @param string $endpoint expanded request path, including any base path from the API URL
     */
    public function __construct(
        public string $method,
        public string $endpoint,
        ?string $route = null,
        public ?string $storeId = null,
        public bool $idempotent = true,
    ) {
        $this->route = $route ?? $endpoint;
    }
}
