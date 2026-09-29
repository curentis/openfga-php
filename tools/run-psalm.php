<?php

declare(strict_types=1);

/**
 * Psalm 6.5 crashes on PHP 8.5+ when scanning Composer autoload files (SplObjectStorage::attach deprecation).
 * CI runs Psalm on PHP 8.3 and 8.4 matrix cells; PHP 8.5 uses PHPStan only until Psalm supports 8.5.
 */
if (PHP_VERSION_ID >= 80500) {
    fwrite(STDERR, "Psalm skipped on PHP " . PHP_VERSION . " (PHPStan still runs; use PHP 8.3–8.4 for Psalm locally).\n");
    exit(0);
}

$psalm = dirname(__DIR__) . '/vendor/bin/psalm';
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($psalm) . ' --no-cache', $code);
exit((int) $code);
