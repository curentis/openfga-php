<?php

declare(strict_types=1);

/**
 * PHP versions tested in CI on every PR. Align with SUPPORTED_RUNTIMES.md.
 *
 * @return list<string>
 */
function openfga_supported_php_versions(): array
{
    return ['8.3', '8.4', '8.5'];
}

/**
 * Lowest supported PHP minor (must match composer.json require).
 */
function openfga_minimum_php_version(): string
{
    return '8.3';
}
