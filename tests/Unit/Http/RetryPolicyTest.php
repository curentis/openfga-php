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
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Observability\RequestFinished;
use Curentis\OpenFga\Observability\RetryScheduled;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Tests\Support\CallCounter;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
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

    private FakeSleeper $sleeper;

    private Randomizer $randomizer;

    #[\Override]
    protected function setUp(): void
    {
        $this->sleeper = new FakeSleeper();
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
            new FrozenClock(new DateTimeImmutable('@' . self::FIXED_EPOCH)),
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
            'POST',
            '/stores/abc/check',
            'abc',
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
            'GET',
            '/stores',
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
            'GET',
            '/stores',
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
                'POST',
                '/stores/x/check',
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
            'GET',
            '/healthz',
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
                'GET',
                '/stores',
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
            'GET',
            '/healthz',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHttp300IsNotTreatedAsSuccess(): void
    {
        $policy = $this->policy(maxRetry: 0);

        $this->expectException(FgaApiException::class);
        $policy->send(
            static fn(): Response => new Response(300, [], '{}'),
            'GET',
            '/healthz',
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
                'GET',
                '/stores',
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
                'POST',
                '/stores/abc/check',
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
            $policy->send(static fn(): Response => $response, 'GET', '/stores');
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
                'POST',
                '/stores/s/write',
                's',
                null,
                false,
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
                'POST',
                '/stores/s/write',
                null,
                null,
                false,
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
            'POST',
            '/stores/s/write',
            null,
            null,
            false,
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
                'GET',
                '/stores',
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
                'GET',
                '/stores',
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
            'GET',
            '/stores',
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
            'GET',
            '/healthz',
            null,
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
                'GET',
                '/stores',
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
            'GET',
            '/healthz',
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
                'GET',
                '/stores',
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
                'GET',
                '/stores',
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
            'GET',
            '/healthz',
        );

        self::assertSame([123], $this->sleeper->sleptMilliseconds);
    }

    public function testStartingSpentBelowZeroWouldStillSleepInsideTheBudget(): void
    {
        $policy = $this->policy(maxRetry: 1, maxElapsedMs: 122);
        try {
            $policy->send(
                static fn(): Response => new Response(503, [], '{}'),
                'GET',
                '/healthz',
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
                'GET',
                '/healthz',
            );
            self::fail('Expected the budget to stop the retry');
        } catch (FgaApiInternalException) {
        }

        self::assertSame([123, 298], $this->sleeper->sleptMilliseconds);
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
