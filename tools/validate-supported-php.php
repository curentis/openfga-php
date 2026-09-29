<?php

declare(strict_types=1);

require __DIR__ . '/supported-php-versions.php';

$root = dirname(__DIR__);
$ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
$versions = openfga_supported_php_versions();
$errors = [];

foreach ($versions as $version) {
    if (!str_contains($ci, '"' . $version . '"')) {
        $errors[] = "ci.yml missing PHP {$version} in matrix (update tools/supported-php-versions.php and ci.yml together)";
    }
}

if (preg_match('/matrix\.php\s*!=\s*[\'"]8\.5[\'"]/i', $ci) === 1) {
    $errors[] = 'ci.yml must not skip jobs on PHP 8.5 (all supported versions run the same checks)';
}

$composer = (string) file_get_contents($root . '/composer.json');
$min = openfga_minimum_php_version();
if (preg_match('/"php":\s*"\^' . preg_quote($min, '/') . '"/', $composer) !== 1) {
    $errors[] = "composer.json php constraint must be ^{$min}";
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "Supported PHP versions: OK (" . implode(', ', $versions) . ")\n");
