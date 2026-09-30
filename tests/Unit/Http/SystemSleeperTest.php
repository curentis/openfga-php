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
        $sleeper = new SystemSleeper();
        $sleeper->sleepMs(1);
        self::expectNotToPerformAssertions();
    }
}
