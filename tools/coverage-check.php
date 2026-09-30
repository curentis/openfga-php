<?php

declare(strict_types=1);

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$file = $argv[1] ?? 'build/clover.xml';
// Gate applies to hand-written src/ only (see phpunit.xml.dist); raise toward 90% as unit tests grow.
$min = $argv[2] ?? '40';

$xml = simplexml_load_file($file);
if ($xml === false) {
    throw new RuntimeException("Cannot read {$file}");
}

$metrics = $xml->project->metrics;
$total = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);
$minimum = (float) $min;
$pct = $total === 0 ? 100.0 : ((float) $covered / (float) $total) * 100.0;
printf("Line coverage: %.2f%% (minimum %s%%)\n", $pct, $min);
exit($pct + 1e-9 >= $minimum ? 0 : 1);
