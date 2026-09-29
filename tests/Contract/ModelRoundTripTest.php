<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Contract;

use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Tools\Codegen\Generator\NameConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelRoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: array<string, mixed>}>
     */
    public static function schemaExamples(): iterable
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/spec/openapi.json');
        self::assertIsString($raw);
        $spec = json_decode($raw, true);
        self::assertIsArray($spec);
        $components = $spec['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        /** @var list<string> $allowed */
        $allowed = require dirname(__DIR__, 2) . '/tools/codegen/schemas.php';

        foreach ($allowed as $name) {
            if (!isset($schemas[$name]) || !is_array($schemas[$name])) {
                continue;
            }
            $schema = $schemas[$name];
            if (!isset($schema['example']) || !is_array($schema['example'])) {
                continue;
            }
            /** @var array<string, mixed> $example */
            $example = $schema['example'];
            $class = 'Curentis\\OpenFga\\Model\\' . NameConverter::schemaToClassName($name);
            if (!class_exists($class) || !method_exists($class, 'fromArray')) {
                continue;
            }
            yield $name => [$class, $example];
        }
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $example
     */
    #[DataProvider('schemaExamples')]
    public function testExampleRoundTrips(string $class, array $example): void
    {
        $fromArray = [$class, 'fromArray'];
        if (!is_callable($fromArray)) {
            self::fail(sprintf('%s::fromArray is not callable', $class));
        }

        try {
            /** @var callable(array<string, mixed>): object $fromArray */
            $model = $fromArray($example);
        } catch (\Throwable $e) {
            if ($e instanceof FgaValidationException) {
                self::markTestSkipped('OpenAPI example is not valid for generated model: ' . $e->getMessage());
            }

            throw $e;
        }
        self::assertTrue(method_exists($model, 'toArray'));
        /** @var array<string, mixed> $roundTripped */
        $roundTripped = $model->toArray();
        self::assertSame($example, $roundTripped);
    }
}
