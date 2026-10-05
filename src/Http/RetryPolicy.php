<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Random\Randomizer;

/**
 * Retries retryable HTTP failures with a capped exponential backoff.
 */
final class RetryPolicy implements RetryPolicyInterface
{
    private const int MAX_BACKOFF_MS = 120_000;

    private int $spentMs = 0;

    public function __construct(
        private readonly int $maxRetry,
        private readonly int $minWaitMs,
        private readonly Sleeper $sleeper,
        private readonly ClockInterface $clock,
        private readonly Randomizer $randomizer,
        private readonly ErrorMapper $errorMapper = new ErrorMapper(),
        private readonly int $maxElapsedMs = 10_000,
        private readonly int $maxDelayMs = 5_000,
        private readonly SdkTelemetry $telemetry = new SdkTelemetry(),
    ) {
        if ($maxRetry < 0 || $maxRetry > 15) {
            throw new FgaValidationException(sprintf('maxRetry must be between 0 and 15, got %d.', $maxRetry));
        }
        if ($minWaitMs < 1) {
            throw new FgaValidationException(sprintf('minWaitMs must be at least 1, got %d.', $minWaitMs));
        }
        if ($maxElapsedMs < 1) {
            throw new FgaValidationException(sprintf('maxElapsedMs must be at least 1, got %d.', $maxElapsedMs));
        }
        if ($maxDelayMs < 1) {
            throw new FgaValidationException(sprintf('maxDelayMs must be at least 1, got %d.', $maxDelayMs));
        }
    }

    /**
     * @param callable(): ResponseInterface $send
     */
    #[\Override]
    public function send(
        callable $send,
        string $method,
        string $endpoint,
        ?string $storeId = null,
        ?RetryOptions $retry = null,
        bool $idempotent = true,
    ): ResponseInterface {
        $maxRetry = $retry !== null ? $retry->maxRetry : $this->maxRetry;
        $minWaitMs = $retry !== null ? $retry->minWaitMs : $this->minWaitMs;
        $maxElapsedMs = $retry !== null ? $retry->maxElapsedMs : $this->maxElapsedMs;
        $maxDelayMs = $retry !== null ? $retry->maxDelayMs : $this->maxDelayMs;
        $this->spentMs = 0;
        $attempt = 0;

        while (true) {
            $response = $this->trySend($send, $method, $endpoint, $attempt, $maxRetry, $idempotent, $minWaitMs, $maxDelayMs, $maxElapsedMs);
            if ($response === null) {
                ++$attempt;

                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->telemetry->requestFinished($method, $endpoint, $statusCode, $attempt + 1);

                return $response;
            }

            $retryAfterMs = RetryAfter::delayMs($response, $this->clock);
            $retryable = $this->isRetryableStatus($statusCode, $idempotent);
            if (!$retryable || $attempt >= $maxRetry) {
                $this->telemetry->requestFinished($method, $endpoint, $statusCode, $attempt + 1);

                throw $this->errorMapper->map($method, $endpoint, $storeId, $response, $retryAfterMs);
            }

            if (!$this->sleepBeforeRetry($retryAfterMs, $attempt, $minWaitMs, $maxDelayMs, $maxElapsedMs, $method, $endpoint)) {
                $this->telemetry->requestFinished($method, $endpoint, $statusCode, $attempt + 1);

                throw $this->errorMapper->map($method, $endpoint, $storeId, $response, $retryAfterMs);
            }
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
        int $maxRetry,
        bool $idempotent,
        int $minWaitMs,
        int $maxDelayMs,
        int $maxElapsedMs,
    ): ?ResponseInterface {
        try {
            return $send();
        } catch (NetworkExceptionInterface $exception) {
            if (!$idempotent || $attempt >= $maxRetry) {
                throw new FgaNetworkException(
                    sprintf('OpenFGA API request failed after retries (%s %s).', $method, $endpoint),
                    $method,
                    $endpoint,
                    $exception,
                );
            }
            if (!$this->sleepBeforeRetry(null, $attempt, $minWaitMs, $maxDelayMs, $maxElapsedMs, $method, $endpoint)) {
                throw new FgaNetworkException(
                    sprintf('OpenFGA API request failed after retries (%s %s).', $method, $endpoint),
                    $method,
                    $endpoint,
                    $exception,
                );
            }

            return null;
        } catch (ClientExceptionInterface $exception) {
            throw new FgaNetworkException(
                sprintf('OpenFGA API request failed (%s %s).', $method, $endpoint),
                $method,
                $endpoint,
                $exception,
            );
        }
    }

    private function isRetryableStatus(int $statusCode, bool $idempotent): bool
    {
        if ($statusCode === 429) {
            return true;
        }
        if (!$idempotent) {
            return false;
        }

        return $statusCode >= 500 && $statusCode <= 599 && $statusCode !== 501;
    }

    private function sleepBeforeRetry(
        ?int $retryAfterMs,
        int $attempt,
        int $minWaitMs,
        int $maxDelayMs,
        int $maxElapsedMs,
        string $method,
        string $endpoint,
    ): bool {
        $delayMs = $this->delayMs($retryAfterMs, $attempt, $minWaitMs, $maxDelayMs);
        if ($this->spentMs + $delayMs > $maxElapsedMs) {
            return false;
        }

        $this->spentMs += $delayMs;
        $this->telemetry->retryScheduled($method, $endpoint, $attempt + 1, $delayMs);
        $this->sleeper->sleepMs($delayMs);

        return true;
    }

    private function delayMs(?int $retryAfterMs, int $attempt, int $minWaitMs, int $maxDelayMs): int
    {
        if ($retryAfterMs !== null) {
            $spread = intdiv($retryAfterMs, 10);
            $delayMs = $retryAfterMs + $this->randomizer->getInt(0, $spread);
        } else {
            $exponent = 2 ** $attempt;
            /** @infection-ignore-all */
            $backoffMs = min(self::MAX_BACKOFF_MS, $minWaitMs * (int) $exponent);
            $upperBoundMs = min(2 * $backoffMs, self::MAX_BACKOFF_MS);
            $delayMs = $this->randomizer->getInt($backoffMs, $upperBoundMs);
        }

        return min($delayMs, $maxDelayMs);
    }
}
