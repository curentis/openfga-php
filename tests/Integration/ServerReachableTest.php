<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ServerReachableTest extends TestCase
{
    public function testHealthzReturnsOkWhenServerAvailable(): void
    {
        $configuredUrl = getenv('FGA_API_URL');
        $baseUrl = rtrim(
            $configuredUrl !== false && $configuredUrl !== '' ? $configuredUrl : 'http://localhost:8080',
            '/',
        );
        $requireFlag = getenv('FGA_REQUIRE_SERVER');
        $require = ($requireFlag !== false ? $requireFlag : '0') === '1';

        $context = stream_context_create(['http' => ['timeout' => 2]]);
        $body = @file_get_contents($baseUrl . '/healthz', false, $context);

        if ($body === false) {
            if ($require) {
                self::fail(sprintf('OpenFGA server required at %s but /healthz is unreachable.', $baseUrl));
            }

            self::markTestSkipped(sprintf('OpenFGA not running at %s (start with docker compose).', $baseUrl));
        }

        self::assertNotSame('', $body);
    }
}
