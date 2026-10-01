<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Http\SystemSleeper;
use PHPUnit\Framework\TestCase;

final class SystemSleeperTest extends TestCase
{
    public function testNonPositiveSleepIsNoOp(): void
    {
        $sleeper = new SystemSleeper();
        $sleeper->sleepMs(0);
        $sleeper->sleepMs(-5);
        self::expectNotToPerformAssertions();
    }

    public function testPositiveSleepDoesNotThrow(): void
    {
        $started = hrtime(true);
        (new SystemSleeper())->sleepMs(20);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertGreaterThan(10, $elapsedMs);
    }

    public function testMicrosecondsUsesOneThousandPerMillisecond(): void
    {
        self::assertSame(2000, SystemSleeper::microseconds(2));
    }
}
