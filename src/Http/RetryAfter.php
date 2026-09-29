<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class RetryAfter
{
    private const int MAX_DELAY_SECONDS = 1800;

    public static function delayMs(ResponseInterface $response, ClockInterface $clock): ?int
    {
        $retryAfterLine = trim($response->getHeaderLine('Retry-After'));
        if ($retryAfterLine !== '') {
            $fromRetryAfter = self::parseRetryAfter($retryAfterLine, $clock);
            if ($fromRetryAfter !== null) {
                return $fromRetryAfter;
            }
        }

        foreach (['X-RateLimit-Reset', 'X-Rate-Limit-Reset'] as $headerName) {
            $line = trim($response->getHeaderLine($headerName));
            if ($line === '') {
                continue;
            }

            $fromReset = self::parseRateLimitReset($line, $clock);
            if ($fromReset !== null) {
                return $fromReset;
            }
        }

        return null;
    }

    private static function parseRetryAfter(string $value, ClockInterface $clock): ?int
    {
        if (preg_match('/^\d+$/', $value) === 1) {
            return self::delayMsFromSeconds((int) $value);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $secondsUntil = $timestamp - $clock->now()->getTimestamp();

        return self::delayMsFromSeconds($secondsUntil);
    }

    private static function parseRateLimitReset(string $value, ClockInterface $clock): ?int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $parsed = (int) $value;
        $now = $clock->now()->getTimestamp();
        $secondsUntil = $parsed > $now ? $parsed - $now : $parsed;

        return self::delayMsFromSeconds($secondsUntil);
    }

    private static function delayMsFromSeconds(int $seconds): ?int
    {
        if ($seconds <= 0 || $seconds > self::MAX_DELAY_SECONDS) {
            return null;
        }

        return $seconds * 1000;
    }
}
