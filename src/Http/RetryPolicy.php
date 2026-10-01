<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Random\Randomizer;

/**
 * @internal
 */
final class RetryPolicy
{
    private const int MAX_BACKOFF_MS = 120_000;

    public function __construct(
        private readonly int $maxRetry,
        private readonly int $minWaitMs,
        private readonly Sleeper $sleeper,
        private readonly ClockInterface $clock,
        private readonly Randomizer $randomizer,
        private readonly ErrorMapper $errorMapper = new ErrorMapper(),
    ) {
        if ($maxRetry < 0 || $maxRetry > 15) {
            throw new FgaValidationException(sprintf('maxRetry must be between 0 and 15, got %d.', $maxRetry));
        }
        if ($minWaitMs < 1) {
            throw new FgaValidationException(sprintf('minWaitMs must be at least 1, got %d.', $minWaitMs));
        }
    }

    /**
     * @param callable(): ResponseInterface $send
     */
    public function send(
        callable $send,
        string $method,
        string $endpoint,
        ?string $storeId = null,
    ): ResponseInterface {
        $attempt = 0;

        while (true) {
            $response = $this->trySend($send, $method, $endpoint, $attempt);
            if ($response === null) {
                $this->sleepBeforeRetry(null, $attempt);
                ++$attempt;

                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return $response;
            }

            $retryAfterMs = RetryAfter::delayMs($response, $this->clock);

            if (!$this->isRetryableStatus($statusCode) || $attempt >= $this->maxRetry) {
                throw $this->errorMapper->map($method, $endpoint, $storeId, $response, $retryAfterMs);
            }

            $this->sleepBeforeRetry($retryAfterMs, $attempt);
            ++$attempt;
        }
    }

    /**
     * @param callable(): ResponseInterface $send
     */
    private function trySend(
        callable $send,
        string $method,
        string $endpoint,
        int $attempt,
    ): ?ResponseInterface {
        try {
            return $send();
        } catch (NetworkExceptionInterface $exception) {
            if ($attempt >= $this->maxRetry) {
                throw new FgaNetworkException(
                    sprintf('OpenFGA API request failed after retries (%s %s).', $method, $endpoint),
                    $method,
                    $endpoint,
                    $exception,
                );
            }

            return null;
        }
    }

    private function isRetryableStatus(int $statusCode): bool
    {
        if ($statusCode === 429) {
            return true;
        }

        return $statusCode >= 500 && $statusCode <= 599 && $statusCode !== 501;
    }

    private function sleepBeforeRetry(?int $retryAfterMs, int $attempt): void
    {
        if ($retryAfterMs !== null) {
            $this->sleeper->sleepMs($retryAfterMs);

            return;
        }

        $exponent = 2 ** $attempt;
        /** @infection-ignore-all */
        $backoffMs = min(self::MAX_BACKOFF_MS, $this->minWaitMs * (int) $exponent);
        $upperBoundMs = min(2 * $backoffMs, self::MAX_BACKOFF_MS);
        $delayMs = $this->randomizer->getInt($backoffMs, $upperBoundMs);
        $this->sleeper->sleepMs($delayMs);
    }
}
