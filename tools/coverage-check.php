<?php

declare(strict_types=1);

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
[$_, $file, $min] = array_pad($argv, 3, null);
$file ??= 'build/clover.xml';
$min ??= '90';
$xml = simplexml_load_file($file);
if ($xml === false) {
    throw new RuntimeException("Cannot read {$file}");
}

$m = $xml->project->metrics;
$total = (int) $m['statements'];
$covered = (int) $m['coveredstatements'];
$pct = $total === 0 ? 100.0 : $covered / $total * 100;
printf("Line coverage: %.2f%% (minimum %s%%)\n", $pct, $min);
exit($pct + 1e-9 >= (float) $min ? 0 : 1);
