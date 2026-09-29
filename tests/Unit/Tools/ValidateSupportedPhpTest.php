<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

final class ValidateSupportedPhpTest extends TestCase
{
    public function testValidateSupportedPhpScriptSucceeds(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/validate-supported-php.php');
        exec($command, $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringContainsString('Supported PHP versions: OK', implode("\n", $output));
    }
}
