<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

final class FgaTokenExchangeException extends FgaApiAuthenticationException
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
        public readonly string $issuer,
        public readonly string $audience,
        public readonly string $clientId,
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

    public function __debugInfo(): array
    {
        return [
            'statusCode' => $this->statusCode,
            'issuer' => $this->issuer,
            'audience' => $this->audience,
            'clientId' => $this->clientId,
        ];
    }
}
