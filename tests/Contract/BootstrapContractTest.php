<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class BootstrapContractTest extends TestCase
{
    public function testContractSuiteBootstraps(): void
    {
        $composer = (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertStringContainsString('curentis/openfga-php', $composer);
    }
}
