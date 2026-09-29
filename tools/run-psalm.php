<?php

declare(strict_types=1);

$psalm = dirname(__DIR__) . '/vendor/bin/psalm';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($psalm) . ' --no-cache', $code);
exit($code);
