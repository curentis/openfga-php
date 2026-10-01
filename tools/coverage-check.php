<?php

declare(strict_types=1);

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$file = $argv[1] ?? 'build/clover.xml';
// Gate applies to hand-written src/ only (see phpunit.xml.dist); src/Model is codegen.
$min = $argv[2] ?? '100';
$projectRoot = dirname(__DIR__);

$xml = simplexml_load_file($file);
if ($xml === false) {
    throw new RuntimeException("Cannot read {$file}");
}

$minimum = (float) $min;
$metrics = $xml->project->metrics;
$total = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);
$pct = $total === 0 ? 100.0 : ((float) $covered / (float) $total) * 100.0;
printf("Line coverage: %.2f%% (minimum %s%%)\n", $pct, $min);

$failed = $pct + 1e-9 < $minimum;

/** @var array<string, array{statements: int, covered: int}> $byRelativePath */
$byRelativePath = [];
$fileNodes = $xml->xpath('//file');
if (!is_array($fileNodes)) {
    throw new RuntimeException('Invalid clover: missing file nodes');
}
foreach ($fileNodes as $fileNode) {
    $name = (string) ($fileNode['name'] ?? '');
    if ($name === '') {
        continue;
    }
    $relative = str_starts_with($name, $projectRoot)
        ? substr($name, strlen($projectRoot) + 1)
        : $name;
    if (!str_starts_with($relative, 'src/') || str_starts_with($relative, 'src/Model/')) {
        continue;
    }
    $fileMetrics = $fileNode->metrics;
    $statements = (int) ($fileMetrics['statements'] ?? 0);
    $coveredStatements = (int) ($fileMetrics['coveredstatements'] ?? 0);
    if (!isset($byRelativePath[$relative])) {
        $byRelativePath[$relative] = ['statements' => 0, 'covered' => 0];
    }
    $byRelativePath[$relative]['statements'] += $statements;
    $byRelativePath[$relative]['covered'] += $coveredStatements;
}

$expectedFiles = [];
$srcDir = $projectRoot . '/src';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $path) {
    if (!$path instanceof SplFileInfo || !$path->isFile() || $path->getExtension() !== 'php') {
        continue;
    }
    $relative = 'src/' . substr($path->getPathname(), strlen($srcDir) + 1);
    if (str_starts_with($relative, 'src/Model/')) {
        continue;
    }
    $expectedFiles[] = $relative;
}
sort($expectedFiles);

foreach ($expectedFiles as $relative) {
    if (!isset($byRelativePath[$relative])) {
        fwrite(STDERR, "No coverage data for {$relative}\n");
        $failed = true;
        continue;
    }
    $fileTotal = $byRelativePath[$relative]['statements'];
    $fileCovered = $byRelativePath[$relative]['covered'];
    if ($fileTotal === 0) {
        continue;
    }
    $filePct = ((float) $fileCovered / (float) $fileTotal) * 100.0;
    if ($filePct + 1e-9 < $minimum) {
        fwrite(STDERR, sprintf(
            "File %s: %.2f%% (%d/%d statements)\n",
            $relative,
            $filePct,
            $fileCovered,
            $fileTotal,
        ));
        foreach ($fileNodes as $fileNode) {
            $name = (string) ($fileNode['name'] ?? '');
            $nodeRelative = str_starts_with($name, $projectRoot)
                ? substr($name, strlen($projectRoot) + 1)
                : $name;
            if ($nodeRelative !== $relative) {
                continue;
            }
            foreach ($fileNode->line as $lineNode) {
                $count = (int) ($lineNode['count'] ?? 0);
                if ($count === 0 && (string) ($lineNode['type'] ?? '') === 'stmt') {
                    fwrite(STDERR, sprintf("  uncovered line %s\n", (string) ($lineNode['num'] ?? '?')));
                }
            }
        }
        $failed = true;
    }
}

exit($failed ? 1 : 0);
