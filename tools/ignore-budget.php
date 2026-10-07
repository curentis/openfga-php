<?php

declare(strict_types=1);

// Ceilings for hand-written src/ (src/Model is codegen). Raising one is a reviewed decision:
// every new ignore needs a comment explaining why the mutant or line cannot be exercised.
$budgets = [
    '@infection-ignore-all' => 28,
    '@codeCoverageIgnoreStart' => 5,
];

$root = dirname(__DIR__) . '/src';
$counts = array_fill_keys(array_keys($budgets), 0);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_starts_with($path, $root . '/Model/')) {
        continue;
    }
    $source = (string) file_get_contents($path);
    foreach (array_keys($budgets) as $marker) {
        $counts[$marker] += substr_count($source, $marker);
    }
}

$failed = false;
foreach ($budgets as $marker => $budget) {
    $count = $counts[$marker];
    printf("%s: %d (budget %d)\n", $marker, $count, $budget);
    if ($count > $budget) {
        fwrite(STDERR, sprintf("%s exceeds its budget by %d. Kill the mutant or cover the line instead.\n", $marker, $count - $budget));
        $failed = true;
    }
}

exit($failed ? 1 : 0);
