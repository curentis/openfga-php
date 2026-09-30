<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\AccessToken;
use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Credentials\TokenProvider;
use Curentis\OpenFga\Exception\FgaTokenExchangeException;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Tests\Support\RsaPrivateKeyFixture;
use Curentis\OpenFga\Tests\Support\SimpleArrayCache;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class TokenProviderTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    public function testRefreshesExpiredMemoryToken(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"second","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $memory = new \ReflectionProperty(TokenProvider::class, 'memoryToken');
        $memory->setValue($provider, new AccessToken('expired', self::NOW - 1));

        self::assertSame('second', $provider->getAccessToken());
        self::assertCount(1, $mock->getRequests());
    }

    public function testExpiredCacheEntryIsIgnored(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $key = 'openfga_token_' . hash('sha256', 'https://issuer.example|client|audience');
        $cache->set($key, 'stale|' . (self::NOW - 10), 3600);

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('fresh', $provider->getAccessToken());
    }

    public function testFetchesAndCachesAccessTokenInMemory(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"first","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('first', $provider->getAccessToken());
        self::assertSame('first', $provider->getAccessToken());
        self::assertCount(1, $mock->getRequests());
    }

    public function testOmitsScopeWhenNotConfigured(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"plain","expires_in":3600}'));
        $provider = $this->provider($mock, new ClientCredentials('client', 'secret', 'issuer.example', 'audience'));

        $provider->getAccessToken();
        $body = urldecode((string) $this->lastTokenRequest($mock)->getBody());
        self::assertStringNotContainsString('scope=', $body);
    }

    public function testIncludesScopeForClientCredentials(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"scoped","expires_in":3600}'));
        $provider = $this->provider($mock, new ClientCredentials(
            'client',
            'secret',
            'issuer.example',
            'audience',
            scopes: 'read write',
        ));

        $provider->getAccessToken();
        $body = (string) $this->lastTokenRequest($mock)->getBody();
        self::assertStringContainsString('scope=read', urldecode($body));
    }

    public function testClientAssertionUsesJwtBearerGrant(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"jwt-token","expires_in":3600}'));
        $credentials = new ClientAssertion(
            'client',
            RsaPrivateKeyFixture::pem(),
            'issuer.example',
            'audience',
        );
        $provider = $this->provider($mock, $credentials);

        self::assertSame('jwt-token', $provider->getAccessToken());
        $body = urldecode((string) $this->lastTokenRequest($mock)->getBody());
        self::assertStringContainsString('client_assertion_type=', $body);
        self::assertStringContainsString('client_assertion=', $body);
    }

    public function testLoadsValidTokenFromPsrCache(): void
    {
        $mock = new MockClient();
        $cache = new SimpleArrayCache();
        $expiresAt = self::NOW + 3600;
        $cache->set('openfga_token_' . hash('sha256', 'https://issuer.example|client|audience'), 'cached|' . $expiresAt, 3600);

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('cached', $provider->getAccessToken());
        self::assertCount(0, $mock->getRequests());
    }

    public function testIgnoresMalformedCacheEntries(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $key = 'openfga_token_' . hash('sha256', 'https://issuer.example|client|audience');
        $cache->set($key, 'broken');
        $cache->set($key . '_2', 'token|not-a-number');

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('fresh', $provider->getAccessToken());
    }

    public function testNumericStringExpiresInIsAccepted(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"num","expires_in":"120"}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('num', $provider->getAccessToken());
    }

    public function testInvalidTokenResponseThrowsExchangeException(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"","expires_in":0}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $this->expectExceptionMessage('invalid token response');
        $provider->getAccessToken();
    }

    public function testHttpErrorFromRetryPolicyPreventsTokenExchange(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(401, [], '{"code":"invalid_client","message":"nope"}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(\Curentis\OpenFga\Exception\FgaApiAuthenticationException::class);
        $provider->getAccessToken();
    }

    private function lastTokenRequest(MockClient $mock): RequestInterface
    {
        $request = $mock->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    private function clientCredentials(): ClientCredentials
    {
        return new ClientCredentials('client', 'secret', 'issuer.example', 'audience');
    }

    private function provider(
        MockClient $mock,
        ClientCredentials|ClientAssertion $credentials,
        ?SimpleArrayCache $cache = null,
    ): TokenProvider {
        $factories = new Psr17Factory();
        $retry = new RetryPolicy(
            0,
            1,
            new FakeSleeper(),
            new FrozenClock(new \DateTimeImmutable('@' . self::NOW)),
            new Randomizer(new Mt19937(1)),
        );

        return new TokenProvider(
            $credentials,
            $mock,
            $factories,
            $factories,
            $retry,
            new FrozenClock(new \DateTimeImmutable('@' . self::NOW)),
            new Randomizer(new Mt19937(1)),
            cache: $cache,
        );
    }
}
