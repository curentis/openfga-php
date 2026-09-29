<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class SupportedPhpVersionsTest extends TestCase
{
    public function testCiWorkflowRunsCheckAndIntegrationOnAllSupportedPhpVersions(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/supported-php-versions.php';
        $versions = openfga_supported_php_versions();
        self::assertNotEmpty($versions);

        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: check (PHP ${{ matrix.php }}', $ci);
        self::assertStringContainsString('name: integration (PHP ${{ matrix.php }}', $ci);
        self::assertStringContainsString('php-version: ${{ matrix.php }}', $ci);

        foreach ($versions as $version) {
            self::assertStringContainsString(
                sprintf('"%s"', $version),
                $ci,
                sprintf('CI workflow must include PHP %s in a matrix.', $version),
            );
        }

        self::assertStringNotContainsString(
            "matrix.php != '8.5'",
            $ci,
            'CI must run the full check job on PHP 8.5 (no matrix exclusions for 8.5).',
        );
        self::assertStringContainsString('composer psalm', $ci);
    }

    public function testComposerRequireMatchesSupportedMinimum(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/supported-php-versions.php';

        $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        $minimum = openfga_minimum_php_version();
        self::assertMatchesRegularExpression(
            '/"php":\s*"\^' . preg_quote($minimum, '/') . '"/',
            $raw,
        );
    }

    public function testSupportedRuntimesDocListsEveryCiVersion(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/supported-php-versions.php';
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/SUPPORTED_RUNTIMES.md');

        foreach (openfga_supported_php_versions() as $version) {
            self::assertMatchesRegularExpression(
                '/\*\*' . preg_quote($version, '/') . '\*\*/',
                $doc,
                sprintf('SUPPORTED_RUNTIMES.md must document PHP %s.', $version),
            );
        }
    }

    public function testRunningPhpMeetsMinimum(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/supported-php-versions.php';
        self::assertTrue(
            version_compare(PHP_VERSION, openfga_minimum_php_version(), '>='),
            'Run the test suite on PHP ' . openfga_minimum_php_version() . ' or newer.',
        );
    }
}
