<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class DependencyRulesTest extends TestCase
{
    public function testModelLayerDoesNotImportHttpApiOrClient(): void
    {
        $modelDir = dirname(__DIR__, 2) . '/src/Model';
        $violations = [];

        $files = glob($modelDir . '/*.php');
        foreach ($files !== false ? $files : [] as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^use Curentis\\\\OpenFga\\\\(Http|Api|Client)\\\\/m', $contents) === 1) {
                $violations[] = basename($file);
            }
        }

        self::assertSame([], $violations, 'Model classes must not depend on Http, Api, or Client');
    }

    public function testSrcDoesNotReferenceGuzzleOrSymfony(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Adapter' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(GuzzleHttp|Symfony\\\\)/', $contents) === 1) {
                $violations[] = str_replace($srcDir . '/', '', $file->getPathname());
            }
        }

        self::assertSame([], $violations);
    }

    public function testExceptionTypesArePublicApi(): void
    {
        $exceptionDir = dirname(__DIR__, 2) . '/src/Exception';
        $violations = [];
        $files = glob($exceptionDir . '/*.php');
        foreach ($files !== false ? $files : [] as $file) {
            $contents = (string) file_get_contents($file);
            if (str_contains($contents, '@internal')) {
                $violations[] = basename($file);
            }
        }

        self::assertSame([], $violations, 'Exception types are part of the public API');
    }
}
