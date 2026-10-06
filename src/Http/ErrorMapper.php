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

final class ErrorMapper
{
    private const int MAX_MESSAGE_BODY = 512;

    public function map(
        string $method,
        string $endpoint,
        ?string $storeId,
        ResponseInterface $response,
        ?int $retryAfterMs = null,
    ): FgaApiException {
        $statusCode = $response->getStatusCode();
        $headers = $this->normalizeHeaders($response);
        $rawBody = (string) $response->getBody();
        [$apiErrorCode, $apiErrorMessage] = $this->parseErrorBody($rawBody);

        return $this->exceptionForStatus(
            $method,
            $endpoint,
            $storeId,
            $statusCode,
            $apiErrorCode,
            $apiErrorMessage,
            $this->requestIdFromHeaders($headers),
            $headers,
            $rawBody,
            $retryAfterMs,
        );
    }

    public function mapStreamError(
        string $method,
        string $endpoint,
        ?string $storeId,
        int $statusCode,
        ?string $code,
        string $message,
    ): FgaApiException {
        return $this->exceptionForStatus(
            $method,
            $endpoint,
            $storeId,
            $statusCode,
            $code,
            $message,
            null,
            [],
            $message,
            null,
        );
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function exceptionForStatus(
        string $method,
        string $endpoint,
        ?string $storeId,
        int $statusCode,
        ?string $apiErrorCode,
        string $apiErrorMessage,
        ?string $requestId,
        array $headers,
        string $responseBody,
        ?int $retryAfterMs,
    ): FgaApiException {
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
            null,
            $responseBody,
        ];

        if ($statusCode === 422 || $statusCode === 400) {
            return new FgaApiValidationException(...$args);
        }
        if ($statusCode === 401 || $statusCode === 403) {
            return new FgaApiAuthenticationException(...$args);
        }
        if ($statusCode === 404) {
            return new FgaApiNotFoundException(...$args);
        }
        if ($statusCode === 429) {
            return new FgaApiRateLimitException(
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
                null,
                $responseBody,
            );
        }
        // 400, 401, 403, 404, 422, and 429 are returned above, so 400 is not this boundary.
        /** @infection-ignore-all */
        if ($statusCode >= 400 && $statusCode < 500) {
            return new FgaApiException(...$args);
        }

        return new FgaApiInternalException(...$args);
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
    private function parseErrorBody(string $raw): array
    {
        if ($raw === '') {
            // json_decode of an empty string fails and yields the same pair.
            /** @infection-ignore-all */
            return [null, ''];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, $this->bodyForMessage($raw)];
        }

        if (!is_array($decoded)) {
            // Reading offsets on a non-array still falls back to the raw body.
            /** @infection-ignore-all */
            return [null, $this->bodyForMessage($raw)];
        }

        $code = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null;
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            $message = $decoded['message'];
        } else {
            $message = $this->bodyForMessage($raw);
        }

        return [$code, $message];
    }

    private function bodyForMessage(string $raw): string
    {
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw) ?? $raw;
        if (strlen($stripped) <= self::MAX_MESSAGE_BODY) {
            return $stripped;
        }

        return substr($stripped, 0, self::MAX_MESSAGE_BODY) . '...';
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
            /** @infection-ignore-all */
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
