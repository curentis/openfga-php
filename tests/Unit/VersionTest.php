<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit;

use Curentis\OpenFga\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',
            Version::VERSION,
        );
    }
}
