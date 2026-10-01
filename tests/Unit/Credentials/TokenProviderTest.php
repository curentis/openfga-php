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

    public function testOmitsScopeWhenConfiguredAsEmptyString(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"plain","expires_in":3600}'));
        $provider = $this->provider($mock, new ClientCredentials(
            'client',
            'secret',
            'issuer.example',
            'audience',
            scopes: '',
        ));

        $provider->getAccessToken();
        $body = urldecode((string) $this->lastTokenRequest($mock)->getBody());
        self::assertStringNotContainsString('scope=', $body);
    }

    public function testPersistsFetchedTokenInPsrCache(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"cached-new","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);

        self::assertSame('cached-new', $provider->getAccessToken());
        self::assertIsString($cache->get('openfga_token_' . hash('sha256', 'https://issuer.example|client|audience')));
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

    public function testTokenRequestIncludesRequiredOAuthFields(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"plain","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $provider->getAccessToken();
        parse_str(urldecode((string) $this->lastTokenRequest($mock)->getBody()), $fields);
        self::assertSame('client_credentials', $fields['grant_type'] ?? null);
        self::assertSame('client', $fields['client_id'] ?? null);
        self::assertSame('audience', $fields['audience'] ?? null);
        self::assertSame('secret', $fields['client_secret'] ?? null);
    }

    public function testAlphabeticExpiresInTriggersInvalidTokenResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"tok","expires_in":"not-a-number"}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $provider->getAccessToken();
    }

    public function testCacheTtlMatchesTokenExpiryBuffer(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"cached-ttl","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);

        $provider->getAccessToken();
        $key = 'openfga_token_' . hash('sha256', 'https://issuer.example|client|audience');
        self::assertIsString($cache->get($key));
        self::assertSame(3600 - 300 - 59, $cache->lastTtlSecondsFor($key));
    }

    public function testCacheTtlFloorsAtOneSecond(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"cached-floor","expires_in":360}'));
        $cache = new SimpleArrayCache();
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);

        $provider->getAccessToken();
        $key = 'openfga_token_' . hash('sha256', 'https://issuer.example|client|audience');
        self::assertSame(1, $cache->lastTtlSecondsFor($key));
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
        parse_str($body, $fields);
        $assertion = $fields['client_assertion'] ?? null;
        self::assertIsString($assertion);
        $segments = explode('.', $assertion);
        self::assertCount(3, $segments);
        $payloadJson = base64_decode(strtr($segments[1], '-_', '+/'), true);
        self::assertIsString($payloadJson);
        $payload = json_decode($payloadJson, true);
        self::assertIsArray($payload);
        $jti = $payload['jti'] ?? null;
        self::assertIsString($jti);
        self::assertSame(32, strlen($jti));
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
        $cache->set($key, 'tok|' . (self::NOW + 3600) . '|extra');

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('fresh', $provider->getAccessToken());
    }

    public function testIntegerExpiresInIsAccepted(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"int-exp","expires_in":120}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('int-exp', $provider->getAccessToken());
    }

    public function testNumericStringExpiresInIsAccepted(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"num","expires_in":"120"}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('num', $provider->getAccessToken());
    }

    public function testTokenExpiryAppliesRefreshBufferAndJitter(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"timed","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('timed', $provider->getAccessToken());

        $memory = new \ReflectionProperty(TokenProvider::class, 'memoryToken');
        $token = $memory->getValue($provider);
        self::assertInstanceOf(AccessToken::class, $token);
        self::assertSame(self::NOW + 3600 - 300 - 59, $token->expiresAtEpoch);
    }

    public function testNonNumericExpiresInTriggersInvalidTokenResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"tok","expires_in":[]}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $provider->getAccessToken();
    }

    public function testMissingAccessTokenFieldTriggersInvalidTokenResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $provider->getAccessToken();
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
