<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Observability;

final class RequestFinished
{
    /**
     * @param string   $endpoint   expanded path; contains store and model IDs, so prefer `$route` as a metric label
     * @param string   $route      path template such as `/stores/{store_id}/check`
     * @param ?int     $statusCode null when no HTTP response was received
     * @param int      $durationMs wall time across all attempts and backoff sleeps
     */
    public function __construct(
        public readonly string $method,
        public readonly string $endpoint,
        public readonly string $route,
        public readonly ?string $storeId,
        public readonly ?int $statusCode,
        public readonly int $attempts,
        public readonly int $durationMs,
        public readonly RequestOutcome $outcome,
    ) {}
}
