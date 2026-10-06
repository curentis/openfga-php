<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Observability;

use Curentis\OpenFga\Observability\RequestFinished;
use Curentis\OpenFga\Observability\RetryScheduled;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Observability\TokenRefreshed;
use Curentis\OpenFga\Tests\Support\RecordingDispatcher;
use Curentis\OpenFga\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class SdkTelemetryTest extends TestCase
{
    public function testNoopTelemetryAcceptsEvents(): void
    {
        $telemetry = new SdkTelemetry();
        $telemetry->requestFinished('GET', '/stores', 200, 1);
        $telemetry->retryScheduled('GET', '/stores', 1, 10);
        $telemetry->tokenRefreshed('client');
        self::expectNotToPerformAssertions();
    }

    public function testLoggerAndDispatcherReceiveSafeFields(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new RecordingDispatcher();
        $telemetry = new SdkTelemetry($logger, $dispatcher);

        $telemetry->requestFinished('GET', '/stores', 200, 1);
        $telemetry->retryScheduled('GET', '/stores', 2, 15);
        $telemetry->tokenRefreshed('client');

        self::assertSame([
            'method' => 'GET',
            'endpoint' => '/stores',
            'status' => 200,
            'attempts' => 1,
        ], $logger->records[0][2]);
        self::assertSame([
            'method' => 'GET',
            'endpoint' => '/stores',
            'attempt' => 2,
            'delay_ms' => 15,
        ], $logger->records[1][2]);
        self::assertSame(['client_id' => 'client'], $logger->records[2][2]);
        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(1, $dispatcher->events[0]->attempts);
        self::assertInstanceOf(RetryScheduled::class, $dispatcher->events[1]);
        self::assertSame(2, $dispatcher->events[1]->attempt);
        self::assertSame(15, $dispatcher->events[1]->delayMs);
        self::assertInstanceOf(TokenRefreshed::class, $dispatcher->events[2]);
        self::assertSame('client', $dispatcher->events[2]->clientId);
    }

    public function testLoggerWithoutDispatcherStillRecords(): void
    {
        $logger = new RecordingLogger();
        (new SdkTelemetry($logger))->tokenRefreshed('client');
        self::assertSame('openfga.token_refresh', $logger->records[0][1]);
    }

    public function testDispatcherWithoutLoggerStillDispatches(): void
    {
        $dispatcher = new RecordingDispatcher();
        (new SdkTelemetry(null, $dispatcher))->requestFinished('GET', '/healthz', 204, 1);
        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
    }
}
