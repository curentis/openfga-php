<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiRateLimitException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Tests\Support\CallCounter;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
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

    private function policy(int $maxRetry = 3, int $minWaitMs = 100): RetryPolicy
    {
        return new RetryPolicy(
            $maxRetry,
            $minWaitMs,
            $this->sleeper,
            new FrozenClock(new DateTimeImmutable('@' . self::FIXED_EPOCH)),
            $this->randomizer,
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
        self::assertSame([2000], $this->sleeper->sleptMilliseconds);
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
}
