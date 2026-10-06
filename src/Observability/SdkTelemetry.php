<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Observability;

use Curentis\OpenFga\Http\RequestContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Telemetry never fails a request: exceptions from the logger or from event listeners are discarded,
 * and a failing listener is reported through the logger as `openfga.telemetry_failed`.
 */
final class SdkTelemetry
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {}

    public function requestFinished(
        RequestContext $context,
        ?int $statusCode,
        int $attempts,
        int $durationMs,
        RequestOutcome $outcome,
    ): void {
        $this->emit(
            'openfga.request',
            [
                'method' => $context->method,
                'route' => $context->route,
                'endpoint' => $context->endpoint,
                'store_id' => $context->storeId,
                'status' => $statusCode,
                'attempts' => $attempts,
                'duration_ms' => $durationMs,
                'outcome' => $outcome->value,
            ],
            new RequestFinished(
                $context->method,
                $context->endpoint,
                $context->route,
                $context->storeId,
                $statusCode,
                $attempts,
                $durationMs,
                $outcome,
            ),
        );
    }

    public function retryScheduled(RequestContext $context, int $attempt, int $delayMs): void
    {
        $this->emit(
            'openfga.retry',
            [
                'method' => $context->method,
                'route' => $context->route,
                'endpoint' => $context->endpoint,
                'store_id' => $context->storeId,
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ],
            new RetryScheduled($context->method, $context->endpoint, $context->route, $context->storeId, $attempt, $delayMs),
        );
    }

    public function tokenRefreshed(string $clientId): void
    {
        $this->emit('openfga.token_refresh', ['client_id' => $clientId], new TokenRefreshed($clientId));
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function emit(string $message, array $context, object $event): void
    {
        // Logger failures are swallowed, so a missing logger and a failing one behave the same.
        try {
            /** @infection-ignore-all */
            $this->logger?->info($message, $context);
        } catch (\Throwable) {
        }

        try {
            $this->dispatcher?->dispatch($event);
        } catch (\Throwable $exception) {
            try {
                /** @infection-ignore-all */
                $this->logger?->warning('openfga.telemetry_failed', [
                    'event' => $event::class,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            } catch (\Throwable) {
            }
        }
    }
}
