<?php

declare(strict_types=1);

use Curentis\OpenFga\Tools\Codegen\Generator\CodegenRunner;
use Curentis\OpenFga\Tools\Codegen\Generator\SchemaLoader;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$check = in_array('--check', $argv, true);

$root = dirname(__DIR__, 2);
$specFile = $root . '/spec/openapi.json';
$sourceFile = $root . '/spec/SOURCE';
$schemasFile = __DIR__ . '/schemas.php';
$modelDir = $root . '/src/Model';

$loader = SchemaLoader::fromSpecFile($specFile);
/** @var list<string> $allowed */
$allowed = require $schemasFile;
$sha = CodegenRunner::readSourceSha($sourceFile);
$runner = new CodegenRunner($loader, $sha, $allowed);
$files = $runner->generateFiles();

$targetRoot = $check ? sys_get_temp_dir() . '/openfga-php-codegen-' . getmypid() : $root;
if ($check) {
    $targetModel = $targetRoot . '/src/Model';
    if (is_dir($targetModel)) {
        $existingFiles = glob($targetModel . '/*.php');
        if ($existingFiles === false) {
            $existingFiles = [];
        }
        foreach ($existingFiles as $existing) {
            unlink($existing);
        }
    }
}

foreach ($files as $relative => $contents) {
    $path = $targetRoot . '/' . $relative;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Cannot create directory: %s', $dir));
    }
    file_put_contents($path, $contents);
}

$fixer = $root . '/vendor/bin/php-cs-fixer';
$fixModelDir = $check ? $targetRoot . '/src/Model' : $modelDir;
if (is_executable($fixer) && is_dir($fixModelDir)) {
    passthru(sprintf(
        '%s fix --config=%s %s 2>/dev/null',
        escapeshellarg($fixer),
        escapeshellarg($root . '/.php-cs-fixer.dist.php'),
        escapeshellarg($fixModelDir),
    ));
}

if ($check) {
    $drift = false;
    foreach (array_keys($files) as $relative) {
        $generated = $targetRoot . '/' . $relative;
        $committed = $root . '/' . $relative;
        if (!is_file($committed)) {
            fwrite(STDERR, "missing committed file: {$relative}\n");
            $drift = true;
            continue;
        }
        $generatedContents = file_get_contents($generated);
        $committedContents = file_get_contents($committed);
        if ($generatedContents !== $committedContents) {
            fwrite(STDERR, "drift detected: {$relative}\n");
            $drift = true;
        }
    }
    $committedFiles = glob($modelDir . '/*.php');
    if ($committedFiles === false) {
        $committedFiles = [];
    }
    $expected = array_map(static fn(string $rel): string => basename($rel), array_keys($files));
    foreach ($committedFiles as $committed) {
        if (!in_array(basename($committed), $expected, true)) {
            fwrite(STDERR, 'unexpected committed model: ' . basename($committed) . "\n");
            $drift = true;
        }
    }

    if ($drift) {
        fwrite(STDERR, "codegen:check FAILED — run composer codegen\n");
        exit(1);
    }

    fwrite(STDOUT, "codegen:check OK\n");
    exit(0);
}

fwrite(STDOUT, sprintf("codegen: wrote %d model file(s) to src/Model\n", count($files)));
exit(0);
