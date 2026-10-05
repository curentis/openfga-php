<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Observability;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class SdkTelemetry
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {}

    public function requestFinished(string $method, string $endpoint, int $statusCode, int $attempts): void
    {
        $event = new RequestFinished($method, $endpoint, $statusCode, $attempts);
        if ($this->logger !== null) {
            $this->logger->info('openfga.request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $statusCode,
                'attempts' => $attempts,
            ]);
        }
        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch($event);
        }
    }

    public function retryScheduled(string $method, string $endpoint, int $attempt, int $delayMs): void
    {
        $event = new RetryScheduled($method, $endpoint, $attempt, $delayMs);
        if ($this->logger !== null) {
            $this->logger->info('openfga.retry', [
                'method' => $method,
                'endpoint' => $endpoint,
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ]);
        }
        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch($event);
        }
    }

    public function tokenRefreshed(string $clientId): void
    {
        $event = new TokenRefreshed($clientId);
        if ($this->logger !== null) {
            $this->logger->info('openfga.token_refresh', ['client_id' => $clientId]);
        }
        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch($event);
        }
    }
}
