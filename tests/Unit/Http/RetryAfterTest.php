<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\RetryAfter;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use DateTimeImmutable;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryAfterTest extends TestCase
{
    private const int FIXED_EPOCH = 1_700_000_000;

    private FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('@' . self::FIXED_EPOCH));
    }

    public function testRetryAfterSecondsReturnsMilliseconds(): void
    {
        $response = new Response(429, ['Retry-After' => '3']);

        self::assertSame(3000, RetryAfter::delayMs($response, $this->clock));
    }

    public function testRetryAfterHttpDateInFutureReturnsMilliseconds(): void
    {
        $future = gmdate('D, d M Y H:i:s', self::FIXED_EPOCH + 5) . ' GMT';
        $response = new Response(429, ['Retry-After' => $future]);

        self::assertSame(5000, RetryAfter::delayMs($response, $this->clock));
    }

    public function testRetryAfterHttpDateInPastReturnsNull(): void
    {
        $past = gmdate('D, d M Y H:i:s', self::FIXED_EPOCH - 10) . ' GMT';
        $response = new Response(429, ['Retry-After' => $past]);

        self::assertNull(RetryAfter::delayMs($response, $this->clock));
    }

    public function testRetryAfterAboveMaxSecondsReturnsNull(): void
    {
        $response = new Response(429, ['Retry-After' => '1801']);

        self::assertNull(RetryAfter::delayMs($response, $this->clock));
    }

    public function testRateLimitResetEpochForm(): void
    {
        $response = new Response(429, ['X-RateLimit-Reset' => (string) (self::FIXED_EPOCH + 12)]);

        self::assertSame(12000, RetryAfter::delayMs($response, $this->clock));
    }

    public function testRateLimitResetDeltaForm(): void
    {
        $response = new Response(429, ['X-Rate-Limit-Reset' => '8']);

        self::assertSame(8000, RetryAfter::delayMs($response, $this->clock));
    }

    public function testRetryAfterTakesPrecedenceOverRateLimitReset(): void
    {
        $response = new Response(429, [
            'Retry-After' => '2',
            'X-RateLimit-Reset' => (string) (self::FIXED_EPOCH + 60),
        ]);

        self::assertSame(2000, RetryAfter::delayMs($response, $this->clock));
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function garbageHeadersProvider(): iterable
    {
        yield 'retry-after garbage' => [['Retry-After' => 'not-a-date']];
        yield 'rate limit garbage' => [['X-RateLimit-Reset' => 'nope']];
        yield 'empty headers' => [[]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('garbageHeadersProvider')]
    public function testGarbageOrMissingHeadersReturnNull(array $headers): void
    {
        $response = new Response(429, $headers);

        self::assertNull(RetryAfter::delayMs($response, $this->clock));
    }
}
