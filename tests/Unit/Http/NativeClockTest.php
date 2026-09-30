<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\NativeClock;
use PHPUnit\Framework\TestCase;

final class NativeClockTest extends TestCase
{
    public function testNowReturnsCurrentTime(): void
    {
        $before = time();
        $clock = new NativeClock();
        $now = $clock->now()->getTimestamp();
        $after = time();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
