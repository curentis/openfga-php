<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Observability\RequestOutcome;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Random\Randomizer;

/**
 * Retries retryable HTTP failures with a capped exponential backoff.
 *
 * The policy holds no per-call state, so one instance can serve nested or concurrent calls.
 * `maxElapsedMs` is a deadline measured on the injected clock from the start of `send()`:
 * it covers request time and backoff, and no retry is scheduled whose sleep would end past it.
 */
final class RetryPolicy implements RetryPolicyInterface
{
    private const int MAX_BACKOFF_MS = 120_000;

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

    public static function fromOptions(
        RetryOptions $options,
        Sleeper $sleeper,
        ClockInterface $clock,
        Randomizer $randomizer,
        SdkTelemetry $telemetry = new SdkTelemetry(),
    ): self {
        return new self(
            $options->maxRetry,
            $options->minWaitMs,
            $sleeper,
            $clock,
            $randomizer,
            maxElapsedMs: $options->maxElapsedMs,
            maxDelayMs: $options->maxDelayMs,
            telemetry: $telemetry,
        );
    }

    /**
     * @param callable(): ResponseInterface $send
     */
    #[\Override]
    public function send(callable $send, RequestContext $context, ?RetryOptions $retry = null): ResponseInterface
    {
        $maxRetry = $retry !== null ? $retry->maxRetry : $this->maxRetry;
        $minWaitMs = $retry !== null ? $retry->minWaitMs : $this->minWaitMs;
        $maxElapsedMs = $retry !== null ? $retry->maxElapsedMs : $this->maxElapsedMs;
        $maxDelayMs = $retry !== null ? $retry->maxDelayMs : $this->maxDelayMs;
        $startedMs = $this->nowMs();
        $deadlineMs = $startedMs + $maxElapsedMs;
        $attempt = 0;

        while (true) {
            try {
                $response = $send();
            } catch (NetworkExceptionInterface $exception) {
                $response = $exception;
            } catch (ClientExceptionInterface $exception) {
                $this->finished($context, null, $attempt, $startedMs, RequestOutcome::NetworkError);

                throw new FgaNetworkException(
                    sprintf('OpenFGA API request failed (%s %s).', $context->method, $context->endpoint),
                    $context->method,
                    $context->endpoint,
                    $exception,
                );
            }

            if ($response instanceof NetworkExceptionInterface) {
                if (
                    !$context->idempotent
                    || $attempt >= $maxRetry
                    || !$this->sleepBeforeRetry(null, $attempt, $minWaitMs, $maxDelayMs, $deadlineMs, $context)
                ) {
                    $this->finished($context, null, $attempt, $startedMs, RequestOutcome::NetworkError);

                    throw new FgaNetworkException(
                        sprintf('OpenFGA API request failed after retries (%s %s).', $context->method, $context->endpoint),
                        $context->method,
                        $context->endpoint,
                        $response,
                    );
                }
                ++$attempt;

                continue;
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->finished($context, $statusCode, $attempt, $startedMs, RequestOutcome::Success);

                return $response;
            }

            $retryAfterMs = RetryAfter::delayMs($response, $this->clock);
            if (
                !$this->isRetryableStatus($statusCode, $context->idempotent)
                || $attempt >= $maxRetry
                || !$this->sleepBeforeRetry($retryAfterMs, $attempt, $minWaitMs, $maxDelayMs, $deadlineMs, $context)
            ) {
                $this->finished($context, $statusCode, $attempt, $startedMs, RequestOutcome::HttpError);

                throw $this->errorMapper->map($context->method, $context->endpoint, $context->storeId, $response, $retryAfterMs);
            }
            ++$attempt;
        }
    }

    private function finished(RequestContext $context, ?int $statusCode, int $attempt, int $startedMs, RequestOutcome $outcome): void
    {
        $this->telemetry->requestFinished($context, $statusCode, $attempt + 1, $this->nowMs() - $startedMs, $outcome);
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
        int $deadlineMs,
        RequestContext $context,
    ): bool {
        $delayMs = $this->delayMs($retryAfterMs, $attempt, $minWaitMs, $maxDelayMs);
        if ($this->nowMs() + $delayMs > $deadlineMs) {
            return false;
        }

        $this->telemetry->retryScheduled($context, $attempt + 1, $delayMs);
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

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
