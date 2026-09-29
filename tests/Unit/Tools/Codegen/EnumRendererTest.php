<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Tools\Codegen;

use Curentis\OpenFga\Tools\Codegen\Generator\EnumRenderer;
use PHPUnit\Framework\TestCase;

final class EnumRendererTest extends TestCase
{
    public function testRendersBackedStringEnum(): void
    {
        $renderer = new EnumRenderer('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
        $source = $renderer->render('ConsistencyPreference', [
            'type' => 'string',
            'enum' => ['UNSPECIFIED', 'MINIMIZE_LATENCY', 'HIGHER_CONSISTENCY'],
        ]);

        self::assertStringContainsString('enum ConsistencyPreference: string', $source);
        self::assertStringContainsString("case UNSPECIFIED = 'UNSPECIFIED';", $source);
        self::assertStringContainsString("case MINIMIZE_LATENCY = 'MINIMIZE_LATENCY';", $source);
    }
}
