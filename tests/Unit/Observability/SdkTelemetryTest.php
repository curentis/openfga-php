<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Observability;

use Curentis\OpenFga\Http\RequestContext;
use Curentis\OpenFga\Observability\RequestFinished;
use Curentis\OpenFga\Observability\RequestOutcome;
use Curentis\OpenFga\Observability\RetryScheduled;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Observability\TokenRefreshed;
use Curentis\OpenFga\Tests\Support\RecordingDispatcher;
use Curentis\OpenFga\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Stringable;

final class SdkTelemetryTest extends TestCase
{
    private static function context(): RequestContext
    {
        return new RequestContext('GET', '/stores/s1/check', '/stores/{store_id}/check', 's1');
    }

    public function testNoopTelemetryAcceptsEvents(): void
    {
        $telemetry = new SdkTelemetry();
        $telemetry->requestFinished(self::context(), 200, 1, 3, RequestOutcome::Success);
        $telemetry->retryScheduled(self::context(), 1, 10);
        $telemetry->tokenRefreshed('client');
        self::expectNotToPerformAssertions();
    }

    public function testLoggerAndDispatcherReceiveSafeFields(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new RecordingDispatcher();
        $telemetry = new SdkTelemetry($logger, $dispatcher);

        $telemetry->requestFinished(self::context(), 200, 1, 12, RequestOutcome::Success);
        $telemetry->retryScheduled(self::context(), 2, 15);
        $telemetry->tokenRefreshed('client');

        self::assertSame('openfga.request', $logger->records[0][1]);
        self::assertSame([
            'method' => 'GET',
            'route' => '/stores/{store_id}/check',
            'endpoint' => '/stores/s1/check',
            'store_id' => 's1',
            'status' => 200,
            'attempts' => 1,
            'duration_ms' => 12,
            'outcome' => 'success',
        ], $logger->records[0][2]);
        self::assertSame('openfga.retry', $logger->records[1][1]);
        self::assertSame([
            'method' => 'GET',
            'route' => '/stores/{store_id}/check',
            'endpoint' => '/stores/s1/check',
            'store_id' => 's1',
            'attempt' => 2,
            'delay_ms' => 15,
        ], $logger->records[1][2]);
        self::assertSame(['client_id' => 'client'], $logger->records[2][2]);

        $finished = $dispatcher->events[0];
        self::assertInstanceOf(RequestFinished::class, $finished);
        self::assertSame('GET', $finished->method);
        self::assertSame('/stores/s1/check', $finished->endpoint);
        self::assertSame('/stores/{store_id}/check', $finished->route);
        self::assertSame('s1', $finished->storeId);
        self::assertSame(200, $finished->statusCode);
        self::assertSame(1, $finished->attempts);
        self::assertSame(12, $finished->durationMs);
        self::assertSame(RequestOutcome::Success, $finished->outcome);

        $scheduled = $dispatcher->events[1];
        self::assertInstanceOf(RetryScheduled::class, $scheduled);
        self::assertSame('GET', $scheduled->method);
        self::assertSame('/stores/s1/check', $scheduled->endpoint);
        self::assertSame('/stores/{store_id}/check', $scheduled->route);
        self::assertSame('s1', $scheduled->storeId);
        self::assertSame(2, $scheduled->attempt);
        self::assertSame(15, $scheduled->delayMs);
        self::assertInstanceOf(TokenRefreshed::class, $dispatcher->events[2]);
        self::assertSame('client', $dispatcher->events[2]->clientId);
    }

    public function testRouteDefaultsToTheEndpoint(): void
    {
        self::assertSame('/stores', (new RequestContext('GET', '/stores'))->route);
    }

    public function testLoggerWithoutDispatcherStillRecords(): void
    {
        $logger = new RecordingLogger();
        (new SdkTelemetry($logger))->tokenRefreshed('client');
        self::assertCount(1, $logger->records);
        self::assertSame('openfga.token_refresh', $logger->records[0][1]);
    }

    public function testDispatcherWithoutLoggerStillDispatches(): void
    {
        $dispatcher = new RecordingDispatcher();
        (new SdkTelemetry(null, $dispatcher))->requestFinished(self::context(), 204, 1, 0, RequestOutcome::Success);
        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
    }

    public function testThrowingListenerIsReportedAndDoesNotEscape(): void
    {
        $logger = new RecordingLogger();
        (new SdkTelemetry($logger, new ThrowingDispatcher()))->tokenRefreshed('client');

        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[1][0]);
        self::assertSame('openfga.telemetry_failed', $logger->records[1][1]);
        self::assertSame([
            'event' => TokenRefreshed::class,
            'exception' => \RuntimeException::class,
            'message' => 'listener failed',
        ], $logger->records[1][2]);
    }

    public function testThrowingListenerWithoutLoggerDoesNotEscape(): void
    {
        (new SdkTelemetry(null, new ThrowingDispatcher()))->tokenRefreshed('client');
        self::expectNotToPerformAssertions();
    }

    public function testThrowingLoggerDoesNotEscapeOrStopDispatch(): void
    {
        $dispatcher = new RecordingDispatcher();
        (new SdkTelemetry(new ThrowingLogger(), $dispatcher))->tokenRefreshed('client');
        (new SdkTelemetry(new ThrowingLogger(), new ThrowingDispatcher()))->tokenRefreshed('client');

        self::assertCount(1, $dispatcher->events);
    }
}

final class ThrowingDispatcher implements EventDispatcherInterface
{
    #[\Override]
    public function dispatch(object $event): object
    {
        throw new \RuntimeException('listener failed');
    }
}

final class ThrowingLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('logger failed');
    }
}
