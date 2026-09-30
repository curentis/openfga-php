<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

trait RequiresOpenFgaServerTrait
{
    protected function openFgaBaseUrl(): string
    {
        $configuredUrl = getenv('FGA_API_URL');
        if ($configuredUrl !== false && $configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
        }

        return 'http://localhost:8080';
    }

    protected function skipUnlessOpenFgaReachable(): void
    {
        $baseUrl = $this->openFgaBaseUrl();
        $requireFlag = getenv('FGA_REQUIRE_SERVER');
        $require = ($requireFlag !== false ? $requireFlag : '0') === '1';

        $context = stream_context_create(['http' => ['timeout' => 2]]);
        if (@file_get_contents($baseUrl . '/healthz', false, $context) === false) {
            if ($require) {
                self::fail(sprintf('OpenFGA server required at %s but /healthz is unreachable.', $baseUrl));
            }

            self::markTestSkipped(sprintf('OpenFGA not running at %s (start with docker compose).', $baseUrl));
        }
    }
}
