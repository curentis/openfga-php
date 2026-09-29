<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Tools\Codegen;

use Curentis\OpenFga\Tools\Codegen\Generator\ClassRenderer;
use Curentis\OpenFga\Tools\Codegen\Generator\SchemaLoader;
use Curentis\OpenFga\Tools\Codegen\Generator\TypeMapper;
use PHPUnit\Framework\TestCase;

final class ClassRendererTest extends TestCase
{
    public function testRendersMinimalObjectSchema(): void
    {
        $spec = [
            'components' => [
                'schemas' => [
                    'Example' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $loader = new SchemaLoader($spec);
        $mapper = new TypeMapper($loader, ['Example' => 'Example']);
        $renderer = new ClassRenderer($loader, $mapper, 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
        $source = $renderer->render('Example', $spec['components']['schemas']['Example']);

        $golden = file_get_contents(__DIR__ . '/../../../Support/Fixtures/codegen/Example.php.txt');
        self::assertIsString($golden);
        self::assertSame($this->normalize($golden), $this->normalize($source));
    }

    private function normalize(string $code): string
    {
        return str_replace("\r\n", "\n", trim($code)) . "\n";
    }
}
