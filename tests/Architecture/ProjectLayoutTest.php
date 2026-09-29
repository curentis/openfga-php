<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Architecture;

use Curentis\OpenFga\Version;
use PHPUnit\Framework\TestCase;

final class ProjectLayoutTest extends TestCase
{
    public function testVersionClassIsLoadable(): void
    {
        self::assertStringStartsWith('0.', Version::VERSION);
    }
}
