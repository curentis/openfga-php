<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiRateLimitException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class ErrorMapper
{
    public function map(
        string $method,
        string $endpoint,
        ?string $storeId,
        ResponseInterface $response,
        ?int $retryAfterMs = null,
    ): FgaApiException {
        $statusCode = $response->getStatusCode();
        $headers = $this->normalizeHeaders($response);
        $requestId = $this->requestIdFromHeaders($headers);
        [$apiErrorCode, $apiErrorMessage] = $this->parseErrorBody($response);
        $message = $this->formatMessage($method, $endpoint, $statusCode, $apiErrorCode, $apiErrorMessage);

        $args = [
            $message,
            $statusCode,
            $apiErrorCode,
            $apiErrorMessage,
            $requestId,
            $method,
            $endpoint,
            $storeId,
            $headers,
        ];

        $exception = match ($statusCode) {
            400, 422 => new FgaApiValidationException(...$args),
            401, 403 => new FgaApiAuthenticationException(...$args),
            404 => new FgaApiNotFoundException(...$args),
            429 => new FgaApiRateLimitException(
                $message,
                $statusCode,
                $apiErrorCode,
                $apiErrorMessage,
                $requestId,
                $method,
                $endpoint,
                $storeId,
                $headers,
                $retryAfterMs,
            ),
            default => $statusCode >= 400 && $statusCode < 500
                ? new FgaApiException(...$args)
                : new FgaApiInternalException(...$args),
        };

        return $exception;
    }

    private function formatMessage(
        string $method,
        string $endpoint,
        int $statusCode,
        ?string $apiErrorCode,
        string $apiErrorMessage,
    ): string {
        $parts = [
            sprintf('OpenFGA API request failed (%s %s → HTTP %d).', $method, $endpoint, $statusCode),
        ];
        if ($apiErrorCode !== null && $apiErrorCode !== '') {
            $parts[] = sprintf('API code: %s.', $apiErrorCode);
        }
        if ($apiErrorMessage !== '') {
            $parts[] = sprintf('API message: %s.', $apiErrorMessage);
        }
        $parts[] = 'Check the request payload, credentials, and store or model identifiers.';

        return implode(' ', $parts);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function parseErrorBody(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [null, ''];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, $raw];
        }

        if (!is_array($decoded)) {
            return [null, $raw];
        }

        $code = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null;
        $message = isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : $raw;

        return [$code, $message];
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(ResponseInterface $response): array
    {
        $normalized = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (!is_string($name)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            $normalized[strtolower($name)] = array_values($values);
        }

        return $normalized;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function requestIdFromHeaders(array $headers): ?string
    {
        foreach (['x-request-id', 'fga-query-id'] as $name) {
            if (isset($headers[$name][0]) && $headers[$name][0] !== '') {
                return $headers[$name][0];
            }
        }

        return null;
    }
}
