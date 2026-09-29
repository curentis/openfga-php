<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

class FgaApiException extends \RuntimeException implements FgaException
{
    /**
     * @param array<string, list<string>> $responseHeaders
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $apiErrorCode,
        public readonly string $apiErrorMessage,
        public readonly ?string $requestId,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly ?string $storeId,
        public readonly array $responseHeaders,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
