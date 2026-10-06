<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Integration;

use Curentis\OpenFga\Client\ClientConfiguration;
use Curentis\OpenFga\Client\OpenFgaClientFactory;
use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use PHPUnit\Framework\TestCase;

/**
 * Runs against an OpenFGA started with `--authn-method=preshared`; see the integration job in CI.
 */
final class PresharedKeyIntegrationTest extends TestCase
{
    public function testApiTokenAuthenticatesAndAMissingTokenIsRejected(): void
    {
        $url = getenv('FGA_PRESHARED_API_URL');
        $key = getenv('FGA_PRESHARED_KEY');
        if (!is_string($url) || $url === '' || !is_string($key) || $key === '') {
            self::markTestSkipped('Set FGA_PRESHARED_API_URL and FGA_PRESHARED_KEY to run against a preshared-key OpenFGA.');
        }

        $authenticated = OpenFgaClientFactory::create(new ClientConfiguration(
            apiUrl: $url,
            credentials: new ApiToken($key),
        ));
        $storeId = $authenticated->createStore('preshared-' . bin2hex(random_bytes(4)))->id;
        $authenticated->withStoreId($storeId)->deleteStore();

        $anonymous = OpenFgaClientFactory::create(new ClientConfiguration(apiUrl: $url));
        try {
            $anonymous->listStores();
            self::fail('Expected the server to reject a request without a token');
        } catch (FgaApiAuthenticationException $exception) {
            self::assertSame(401, $exception->statusCode);
        }
    }
}
