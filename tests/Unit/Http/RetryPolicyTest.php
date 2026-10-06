<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiRateLimitException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\RequestContext;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Observability\RequestFinished;
use Curentis\OpenFga\Observability\RequestOutcome;
use Curentis\OpenFga\Observability\RetryScheduled;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Tests\Support\CallCounter;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Tests\Support\ManualClock;
use Curentis\OpenFga\Tests\Support\RecordingDispatcher;
use Curentis\OpenFga\Tests\Support\RecordingLogger;
use Curentis\OpenFga\Tests\Support\TestClientException;
use Curentis\OpenFga\Tests\Support\TestNetworkException;
use DateTimeImmutable;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RetryPolicyTest extends TestCase
{
    private const int FIXED_EPOCH = 1_700_000_000;

    private ManualClock $clock;

    private FakeSleeper $sleeper;

    private Randomizer $randomizer;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new ManualClock(self::FIXED_EPOCH * 1000);
        $this->sleeper = new FakeSleeper($this->clock);
        $this->randomizer = new Randomizer(new Mt19937(1));
    }

    private function policy(
        int $maxRetry = 3,
        int $minWaitMs = 100,
        int $maxElapsedMs = 10_000,
        int $maxDelayMs = 5_000,
        ?SdkTelemetry $telemetry = null,
    ): RetryPolicy {
        return new RetryPolicy(
            $maxRetry,
            $minWaitMs,
            $this->sleeper,
            $this->clock,
            $this->randomizer,
            maxElapsedMs: $maxElapsedMs,
            maxDelayMs: $maxDelayMs,
            telemetry: $telemetry ?? new SdkTelemetry(),
        );
    }

    public function testHonoursRetryAfterSeconds(): void
    {
        $policy = $this->policy();
        $counter = new CallCounter();
        $response = $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(429, ['Retry-After' => '2'], '{"code":"rate_limit","message":"slow down"}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('POST', '/stores/abc/check', storeId: 'abc'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $counter->count);
        self::assertSame([2121], $this->sleeper->sleptMilliseconds);
    }

    public function testInvalidRetryAfterFallsBackToSeededBackoff(): void
    {
        $policy = $this->policy(maxRetry: 1);
        $counter = new CallCounter();

        $response = $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(429, ['Retry-After' => '3600'], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/stores'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([123], $this->sleeper->sleptMilliseconds);
    }

    public function testRetryAfterHttpDateInPastFallsBackToBackoff(): void
    {
        $policy = $this->policy(maxRetry: 1);
        $past = gmdate('D, d M Y H:i:s', self::FIXED_EPOCH - 30) . ' GMT';
        $counter = new CallCounter();

        $policy->send(
            function () use ($counter, $past): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(429, ['Retry-After' => $past], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/stores'),
        );

        self::assertSame([123], $this->sleeper->sleptMilliseconds);
    }

    /**
     * @return iterable<string, array{0: int, 1: class-string<FgaApiException>}>
     */
    public static function nonRetryableStatusProvider(): iterable
    {
        yield '400 validation' => [400, FgaApiValidationException::class];
        yield '401 authentication' => [401, FgaApiAuthenticationException::class];
        yield '403 authentication' => [403, FgaApiAuthenticationException::class];
        yield '404 not found' => [404, FgaApiNotFoundException::class];
        yield '422 validation' => [422, FgaApiValidationException::class];
        yield '501 not implemented' => [501, FgaApiInternalException::class];
    }

    /**
     * @param class-string<FgaApiException> $expectedClass
     */
    #[DataProvider('nonRetryableStatusProvider')]
    public function testDoesNotRetryNonRetryableStatuses(int $status, string $expectedClass): void
    {
        $policy = $this->policy(maxRetry: 3);
        $counter = new CallCounter();

        try {
            $policy->send(
                function () use ($counter, $status): Response {
                    $counter->increment();

                    return new Response($status, [], '{"code":"err","message":"nope"}');
                },
                new RequestContext('POST', '/stores/x/check'),
            );
            self::fail('Expected exception');
        } catch (FgaApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame(1, $counter->count);
        }
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function retryableServerErrorProvider(): iterable
    {
        yield '500' => [500];
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
        yield '599' => [599];
    }

    #[DataProvider('retryableServerErrorProvider')]
    public function testRetriesRetryableServerErrors(int $status): void
    {
        $policy = $this->policy(maxRetry: 1);
        $counter = new CallCounter();

        $response = $policy->send(
            function () use ($counter, $status): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response($status, [], '{"code":"internal","message":"try again"}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/healthz'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $counter->count);
        self::assertCount(1, $this->sleeper->sleptMilliseconds);
    }

    public function testMaxRetryZeroPerformsSingleAttempt(): void
    {
        $policy = $this->policy(maxRetry: 0);
        $counter = new CallCounter();

        try {
            $policy->send(
                function () use ($counter): Response {
                    $counter->increment();

                    return new Response(503, [], '{}');
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected exception');
        } catch (FgaApiInternalException) {
            self::assertSame(1, $counter->count);
        }
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testMinWaitBelowOneThrowsValidationException(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('minWaitMs');

        $this->policy(minWaitMs: 0);
    }

    public function testMaxRetryFifteenIsAccepted(): void
    {
        $policy = $this->policy(maxRetry: 15);
        $response = $policy->send(
            static fn(): Response => new Response(200, [], '{}'),
            new RequestContext('GET', '/healthz'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHttp300IsNotTreatedAsSuccess(): void
    {
        $policy = $this->policy(maxRetry: 0);

        $this->expectException(FgaApiException::class);
        $policy->send(
            static fn(): Response => new Response(300, [], '{}'),
            new RequestContext('GET', '/healthz'),
        );
    }

    public function testMaxRetryAboveFifteenThrowsValidationException(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('maxRetry');

        $this->policy(maxRetry: 16);
    }

    public function testDoesNotRetryPastMaxRetryOnServerErrors(): void
    {
        $policy = $this->policy(maxRetry: 1);
        $counter = new CallCounter();

        try {
            $policy->send(
                function () use ($counter): Response {
                    $counter->increment();
                    if ($counter->count > 2) {
                        throw new \RuntimeException('retried past the configured limit');
                    }

                    return new Response(503, [], '{}');
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected exception');
        } catch (FgaApiInternalException) {
            self::assertSame(2, $counter->count);
        }
    }

    public function testNetworkErrorIsRetriedThenSurfacedAsFgaNetworkException(): void
    {
        $policy = $this->policy(maxRetry: 2);
        $counter = new CallCounter();
        $root = new TestNetworkException('connection reset');

        try {
            $policy->send(
                function () use ($counter, $root): Response {
                    $counter->increment();
                    throw $root;
                },
                new RequestContext('POST', '/stores/abc/check'),
            );
            self::fail('Expected exception');
        } catch (FgaNetworkException $exception) {
            self::assertSame($root, $exception->getPrevious());
            self::assertSame(3, $counter->count);
        }
        self::assertSame([123, 298], $this->sleeper->sleptMilliseconds);
    }

    public function testExhaustedRetriesThrowRateLimitExceptionWithRetryAfterMs(): void
    {
        $policy = $this->policy(maxRetry: 0);
        $response = new Response(429, ['Retry-After' => '4'], '{"code":"rate_limit","message":"slow"}');

        try {
            $policy->send(static fn(): Response => $response, new RequestContext('GET', '/stores'));
            self::fail('Expected exception');
        } catch (FgaApiRateLimitException $exception) {
            self::assertSame(4000, $exception->retryAfterMs);
        }
    }

    public function testNonIdempotentCallsDoNotRetryServerOrNetworkErrors(): void
    {
        $policy = $this->policy(maxRetry: 3);
        $counter = new CallCounter();

        try {
            $policy->send(
                function () use ($counter): Response {
                    $counter->increment();

                    return new Response(500, [], '{"code":"internal","message":"boom"}');
                },
                new RequestContext('POST', '/stores/s/write', storeId: 's', idempotent: false),
            );
            self::fail('Expected exception');
        } catch (FgaApiInternalException) {
            self::assertSame(1, $counter->count);
        }

        $networkCalls = new CallCounter();
        try {
            $policy->send(
                function () use ($networkCalls): Response {
                    $networkCalls->increment();
                    throw new TestNetworkException('reset');
                },
                new RequestContext('POST', '/stores/s/write', idempotent: false),
            );
            self::fail('Expected exception');
        } catch (FgaNetworkException) {
            self::assertSame(1, $networkCalls->count);
        }
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testNonIdempotentCallsStillRetryRateLimits(): void
    {
        $policy = $this->policy(maxRetry: 1);
        $counter = new CallCounter();
        $response = $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(429, ['Retry-After' => '1'], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('POST', '/stores/s/write', idempotent: false),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $counter->count);
    }

    public function testAbortsRetryWhenDelayWouldExceedBudget(): void
    {
        $policy = $this->policy(maxRetry: 3, maxElapsedMs: 50);
        $counter = new CallCounter();

        try {
            $policy->send(
                function () use ($counter): Response {
                    $counter->increment();

                    return new Response(429, ['Retry-After' => '2'], '{"code":"rate_limit","message":"slow"}');
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected exception');
        } catch (FgaApiRateLimitException) {
            self::assertSame(1, $counter->count);
        }
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testNetworkRetryAbortsWhenBudgetIsExhausted(): void
    {
        $policy = $this->policy(maxRetry: 3, minWaitMs: 100, maxElapsedMs: 1);
        $counter = new CallCounter();

        try {
            $policy->send(
                function () use ($counter): Response {
                    $counter->increment();
                    throw new TestNetworkException('reset');
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected exception');
        } catch (FgaNetworkException) {
            self::assertSame(1, $counter->count);
        }
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testCapsDelayAtMaxDelayMs(): void
    {
        $policy = $this->policy(maxRetry: 1, maxDelayMs: 50);
        $counter = new CallCounter();
        $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(429, ['Retry-After' => '2'], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/stores'),
        );

        self::assertSame([50], $this->sleeper->sleptMilliseconds);
    }

    public function testPerRequestRetryOptionsOverrideThePolicy(): void
    {
        $policy = $this->policy(maxRetry: 0);
        $counter = new CallCounter();
        $response = $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(503, [], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/healthz'),
            new RetryOptions(maxRetry: 1, minWaitMs: 100),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $counter->count);
    }

    public function testClientExceptionsAreWrappedWithoutRetry(): void
    {
        $policy = $this->policy(maxRetry: 3);
        $root = new TestClientException('bad request factory');

        try {
            $policy->send(
                static function () use ($root): Response {
                    throw $root;
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected exception');
        } catch (FgaNetworkException $exception) {
            self::assertSame($root, $exception->getPrevious());
            self::assertStringContainsString('request failed', $exception->getMessage());
        }
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testEmitsRetryAndCompletionTelemetry(): void
    {
        $logger = new RecordingLogger();
        $dispatcher = new RecordingDispatcher();
        $policy = $this->policy(maxRetry: 1, telemetry: new SdkTelemetry($logger, $dispatcher));
        $counter = new CallCounter();
        $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(503, [], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/healthz'),
        );

        $finished = null;
        $scheduled = null;
        foreach ($dispatcher->events as $event) {
            if ($event instanceof RequestFinished) {
                $finished = $event;
            }
            if ($event instanceof RetryScheduled) {
                $scheduled = $event;
            }
        }
        self::assertInstanceOf(RequestFinished::class, $finished);
        self::assertSame(2, $finished->attempts);
        self::assertSame(200, $finished->statusCode);
        self::assertInstanceOf(RetryScheduled::class, $scheduled);
        self::assertSame(1, $scheduled->attempt);
        self::assertSame(123, $scheduled->delayMs);
    }

    public function testFailedAttemptReportsASingleTry(): void
    {
        $dispatcher = new RecordingDispatcher();
        $policy = $this->policy(maxRetry: 0, telemetry: new SdkTelemetry(dispatcher: $dispatcher));
        try {
            $policy->send(
                static fn(): Response => new Response(500, [], '{}'),
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected an API exception');
        } catch (FgaApiInternalException) {
        }

        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(1, $dispatcher->events[0]->attempts);
    }

    public function testBudgetAbortReportsTheAttemptThatStopped(): void
    {
        $dispatcher = new RecordingDispatcher();
        $policy = $this->policy(maxRetry: 1, maxElapsedMs: 50, telemetry: new SdkTelemetry(dispatcher: $dispatcher));
        try {
            $policy->send(
                static fn(): Response => new Response(429, ['Retry-After' => '2'], '{}'),
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected a rate-limit exception');
        } catch (FgaApiRateLimitException) {
        }

        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(1, $dispatcher->events[0]->attempts);
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testSleepsWhenTheDelayEqualsTheRemainingBudget(): void
    {
        $policy = $this->policy(maxRetry: 1, maxElapsedMs: 123);
        $counter = new CallCounter();
        $policy->send(
            function () use ($counter): Response {
                $counter->increment();
                if ($counter->count === 1) {
                    return new Response(503, [], '{}');
                }

                return new Response(200, [], '{}');
            },
            new RequestContext('GET', '/healthz'),
        );

        self::assertSame([123], $this->sleeper->sleptMilliseconds);
    }

    public function testStartingSpentBelowZeroWouldStillSleepInsideTheBudget(): void
    {
        $policy = $this->policy(maxRetry: 1, maxElapsedMs: 122);
        try {
            $policy->send(
                static fn(): Response => new Response(503, [], '{}'),
                new RequestContext('GET', '/healthz'),
            );
            self::fail('Expected the budget to stop the retry');
        } catch (FgaApiInternalException) {
        }

        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testElapsedBudgetIncludesEarlierSleeps(): void
    {
        $policy = $this->policy(maxRetry: 3, maxElapsedMs: 900);
        try {
            $policy->send(
                static fn(): Response => new Response(503, [], '{}'),
                new RequestContext('GET', '/healthz'),
            );
            self::fail('Expected the budget to stop the retry');
        } catch (FgaApiInternalException) {
        }

        self::assertSame([123, 298], $this->sleeper->sleptMilliseconds);
    }

    public function testRequestTimeCountsTowardTheDeadline(): void
    {
        $policy = $this->policy(maxRetry: 3, maxElapsedMs: 10_000);
        $clock = $this->clock;
        $counter = new CallCounter();
        try {
            $policy->send(
                static function () use ($clock, $counter): Response {
                    $counter->increment();
                    $clock->advanceMs(9_900);

                    return new Response(503, [], '{}');
                },
                new RequestContext('GET', '/healthz'),
            );
            self::fail('Expected the deadline to stop the retry');
        } catch (FgaApiInternalException) {
        }

        self::assertSame(1, $counter->count);
        self::assertSame([], $this->sleeper->sleptMilliseconds);
    }

    public function testNestedCallsDoNotResetTheOuterBudget(): void
    {
        $policy = $this->policy(maxRetry: 3, maxElapsedMs: 900);
        $counter = new CallCounter();
        try {
            $policy->send(
                static function () use ($policy, $counter): Response {
                    $counter->increment();
                    $policy->send(static fn(): Response => new Response(200, [], '{}'), new RequestContext('POST', '/oauth/token'));

                    return new Response(503, [], '{}');
                },
                new RequestContext('GET', '/healthz'),
            );
            self::fail('Expected the budget to stop the retry');
        } catch (FgaApiInternalException) {
        }

        self::assertSame(3, $counter->count);
        self::assertSame([123, 298], $this->sleeper->sleptMilliseconds);
    }

    public function testReportsDurationRouteAndOutcome(): void
    {
        $dispatcher = new RecordingDispatcher();
        $policy = $this->policy(maxRetry: 1, telemetry: new SdkTelemetry(dispatcher: $dispatcher));
        $clock = $this->clock;
        $policy->send(
            static function () use ($clock): Response {
                $clock->advanceMs(40);

                return new Response(200, [], '{}');
            },
            new RequestContext('POST', '/stores/s1/check', '/stores/{store_id}/check', 's1'),
        );

        $finished = $dispatcher->events[0];
        self::assertInstanceOf(RequestFinished::class, $finished);
        self::assertSame('/stores/{store_id}/check', $finished->route);
        self::assertSame('/stores/s1/check', $finished->endpoint);
        self::assertSame('s1', $finished->storeId);
        self::assertSame(40, $finished->durationMs);
        self::assertSame(RequestOutcome::Success, $finished->outcome);
    }

    public function testNetworkFailuresEmitAFinishedEvent(): void
    {
        $dispatcher = new RecordingDispatcher();
        $policy = $this->policy(maxRetry: 1, telemetry: new SdkTelemetry(dispatcher: $dispatcher));
        try {
            $policy->send(
                static function (): Response {
                    throw new TestNetworkException('reset');
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected a network exception');
        } catch (FgaNetworkException) {
        }
        try {
            $policy->send(
                static function (): Response {
                    throw new TestClientException('bad');
                },
                new RequestContext('GET', '/stores'),
            );
            self::fail('Expected a network exception');
        } catch (FgaNetworkException) {
        }

        $finished = array_values(array_filter(
            $dispatcher->events,
            static fn(object $event): bool => $event instanceof RequestFinished,
        ));
        self::assertCount(2, $finished);
        self::assertNull($finished[0]->statusCode);
        self::assertSame(2, $finished[0]->attempts);
        self::assertSame(123, $finished[0]->durationMs);
        self::assertSame(RequestOutcome::NetworkError, $finished[0]->outcome);
        self::assertSame(1, $finished[1]->attempts);
        self::assertSame(RequestOutcome::NetworkError, $finished[1]->outcome);
    }

    public function testHttpErrorsReportTheHttpErrorOutcome(): void
    {
        $dispatcher = new RecordingDispatcher();
        $policy = $this->policy(maxRetry: 0, telemetry: new SdkTelemetry(dispatcher: $dispatcher));
        try {
            $policy->send(static fn(): Response => new Response(404, [], '{}'), new RequestContext('GET', '/stores'));
            self::fail('Expected a not-found exception');
        } catch (FgaApiNotFoundException) {
        }

        self::assertInstanceOf(RequestFinished::class, $dispatcher->events[0]);
        self::assertSame(404, $dispatcher->events[0]->statusCode);
        self::assertSame(RequestOutcome::HttpError, $dispatcher->events[0]->outcome);
    }

    public function testFromOptionsCopiesEveryLimit(): void
    {
        $policy = RetryPolicy::fromOptions(
            new RetryOptions(maxRetry: 2, minWaitMs: 7, maxElapsedMs: 900, maxDelayMs: 40),
            $this->sleeper,
            $this->clock,
            $this->randomizer,
        );

        foreach (['maxRetry' => 2, 'minWaitMs' => 7, 'maxElapsedMs' => 900, 'maxDelayMs' => 40] as $name => $expected) {
            self::assertSame($expected, (new \ReflectionProperty(RetryPolicy::class, $name))->getValue($policy));
        }
    }

    public function testDefaultsMatchTheDocumentedBudget(): void
    {
        $policy = new RetryPolicy(
            3,
            100,
            $this->sleeper,
            new FrozenClock(new DateTimeImmutable('@' . self::FIXED_EPOCH)),
            $this->randomizer,
        );
        $elapsed = new \ReflectionProperty(RetryPolicy::class, 'maxElapsedMs');
        $delay = new \ReflectionProperty(RetryPolicy::class, 'maxDelayMs');

        self::assertSame(10_000, $elapsed->getValue($policy));
        self::assertSame(5_000, $delay->getValue($policy));
    }

    public function testAcceptsAOneMillisecondBudget(): void
    {
        $policy = $this->policy(maxElapsedMs: 1, maxDelayMs: 1);
        $elapsed = new \ReflectionProperty(RetryPolicy::class, 'maxElapsedMs');
        $delay = new \ReflectionProperty(RetryPolicy::class, 'maxDelayMs');

        self::assertSame(1, $elapsed->getValue($policy));
        self::assertSame(1, $delay->getValue($policy));
    }

    public function testRejectsNonPositiveBudget(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->policy(maxElapsedMs: 0);
    }

    public function testRejectsNonPositiveMaxDelay(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->policy(maxDelayMs: 0);
    }
}
