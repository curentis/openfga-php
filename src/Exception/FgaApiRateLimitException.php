<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

/** @internal */
final class FgaApiRateLimitException extends FgaApiException
{
    /**
     * @param array<string, list<string>> $responseHeaders
     */
    public function __construct(
        string $message,
        int $statusCode,
        ?string $apiErrorCode,
        string $apiErrorMessage,
        ?string $requestId,
        string $method,
        string $endpoint,
        ?string $storeId,
        array $responseHeaders,
        public readonly ?int $retryAfterMs = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $statusCode,
            $apiErrorCode,
            $apiErrorMessage,
            $requestId,
            $method,
            $endpoint,
            $storeId,
            $responseHeaders,
            $previous,
        );
    }
}
