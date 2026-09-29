<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Curentis\OpenFga\Tools\WorkflowPolicy;

$policy = new WorkflowPolicy();
$errors = [];

$workflowFiles = glob(__DIR__ . '/../.github/workflows/*.{yml,yaml}', GLOB_BRACE);
if ($workflowFiles === false) {
    $workflowFiles = [];
}

foreach ($workflowFiles as $file) {
    $name = basename($file);
    $yaml = (string) file_get_contents($file);
    $errors = array_merge($errors, $policy->check($name, $yaml));
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "Workflow policy: OK\n";
