<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\AccessToken;
use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Credentials\TokenProvider;
use Curentis\OpenFga\Exception\FgaTokenExchangeException;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Observability\TokenRefreshed;
use Curentis\OpenFga\Tests\Support\FakeSleeper;
use Curentis\OpenFga\Tests\Support\FrozenClock;
use Curentis\OpenFga\Tests\Support\RecordingDispatcher;
use Curentis\OpenFga\Tests\Support\RecordingLogger;
use Curentis\OpenFga\Tests\Support\RsaPrivateKeyFixture;
use Curentis\OpenFga\Tests\Support\SimpleArrayCache;
use Curentis\OpenFga\Tests\Support\TestNetworkException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        $key = self::key();
        $cache->set($key, json_encode(['token' => 'stale', 'expiresAt' => self::NOW - 10], JSON_THROW_ON_ERROR), 3600);

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
        self::assertIsString($cache->get(self::key()));
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
        $key = self::key();
        $stored = $cache->get($key);
        self::assertSame(
            json_encode(
                ['token' => 'cached-ttl', 'expiresAt' => self::NOW + 3600 - 30, 'refreshAt' => self::NOW + 3600 - 300 - 59],
                JSON_THROW_ON_ERROR,
            ),
            $stored,
        );
        self::assertSame(3600 - 30, $cache->lastTtlSecondsFor($key));
    }

    public function testCacheTtlFloorsAtOneSecond(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"cached-floor","expires_in":1}'));
        $cache = new SimpleArrayCache();
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);

        $provider->getAccessToken();
        $key = self::key();
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
        $cache->set(self::key(), json_encode(['token' => 'cached', 'expiresAt' => $expiresAt], JSON_THROW_ON_ERROR), 3600);

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('cached', $provider->getAccessToken());
        self::assertCount(0, $mock->getRequests());
    }

    public function testIgnoresMalformedCacheEntries(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $key = self::key();
        $cache->set($key, 'broken');

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('fresh', $provider->getAccessToken());
    }

    public function testIgnoresCacheEntriesWithExtraSegments(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $key = self::key();
        $cache->set($key, 'tok|' . (self::NOW + 3600) . '|extra');

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('fresh', $provider->getAccessToken());
    }

    public function testIgnoresCacheEntriesThatStartWithSeparator(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $key = self::key();
        $cache->set($key, '|' . (self::NOW + 3600));

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('fresh', $provider->getAccessToken());
    }

    public function testExpiresInOfOneSecondIsAccepted(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"short","expires_in":1}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('short', $provider->getAccessToken());
    }

    public function testSingleCharacterCachedTokenIsAccepted(): void
    {
        $mock = new MockClient();
        $cache = new SimpleArrayCache();
        $cache->set(
            self::key(),
            json_encode(['token' => 'a', 'expiresAt' => self::NOW + 3600], JSON_THROW_ON_ERROR),
            3600,
        );

        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        self::assertSame('a', $provider->getAccessToken());
        self::assertCount(0, $mock->getRequests());
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
        self::assertSame(self::NOW + 3600 - 30, $token->expiresAtEpoch);
        self::assertSame(self::NOW + 3600 - 300 - 59, $token->refreshAtEpoch);
    }

    public function testHardExpiryMarginScalesWithShortLifetimes(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"t","expires_in":100}'));
        $provider = $this->provider($mock, $this->clientCredentials());
        $provider->getAccessToken();

        $token = (new \ReflectionProperty(TokenProvider::class, 'memoryToken'))->getValue($provider);
        self::assertInstanceOf(AccessToken::class, $token);
        self::assertSame(self::NOW + 95, $token->expiresAtEpoch);
    }

    public function testNoHardExpiryMarginBelowTwentySeconds(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"t","expires_in":19}'));
        $provider = $this->provider($mock, $this->clientCredentials());
        $provider->getAccessToken();

        $token = (new \ReflectionProperty(TokenProvider::class, 'memoryToken'))->getValue($provider);
        self::assertInstanceOf(AccessToken::class, $token);
        self::assertSame(self::NOW + 19, $token->expiresAtEpoch);
    }

    public function testAFreshMemoryTokenWinsOverTheCache(): void
    {
        $cache = new SimpleArrayCache();
        $cache->set(self::key(), json_encode(['token' => 'cached', 'expiresAt' => self::NOW + 3600], JSON_THROW_ON_ERROR), 3600);
        $provider = $this->provider(new MockClient(), $this->clientCredentials(), $cache);
        (new \ReflectionProperty(TokenProvider::class, 'memoryToken'))->setValue($provider, new AccessToken('memory', self::NOW + 3600));

        self::assertSame('memory', $provider->getAccessToken());
    }

    public function testAnExpiredCachedTokenIsNeverServedDuringARefresh(): void
    {
        $cache = new SimpleArrayCache();
        $cache->set(self::key(), json_encode(['token' => 'stale', 'expiresAt' => self::NOW], JSON_THROW_ON_ERROR), 60);
        $cache->set(self::key() . '.refresh', '1', 10);
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));

        self::assertSame('fresh', $this->provider($mock, $this->clientCredentials(), $cache)->getAccessToken());
    }

    public function testNonNumericExpiresInTriggersInvalidTokenResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"tok","expires_in":[]}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $this->expectExceptionMessage('expires_in=0');
        $provider->getAccessToken();
    }

    public function testMissingAccessTokenFieldTriggersInvalidTokenResponse(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $this->expectExceptionMessage('expires_in=3600');
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

        $this->expectException(FgaTokenExchangeException::class);
        $provider->getAccessToken();
    }

    public function testOauthErrorDescriptionIsSurfaced(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(401, [], '{"error":"invalid_client","error_description":"bad secret"}'));
        $provider = $this->provider($mock, new ClientCredentials(
            'client',
            'secret',
            'https://issuer.example/custom/token',
            'audience',
        ));

        try {
            $provider->getAccessToken();
            self::fail('Expected token exchange failure');
        } catch (FgaTokenExchangeException $exception) {
            self::assertSame('invalid_client', $exception->apiErrorCode);
            self::assertSame('Token endpoint rejected the client (bad secret).', $exception->getMessage());
            self::assertSame('bad secret', $exception->apiErrorMessage);
            self::assertSame('/custom/token', $exception->endpoint);
        }
    }

    public function testOauthErrorCodeIsUsedWhenDescriptionIsMissing(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(400, [], '{"error":"invalid_client"}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        try {
            $provider->getAccessToken();
            self::fail('Expected token exchange failure');
        } catch (FgaTokenExchangeException $exception) {
            self::assertSame('Token endpoint request failed (invalid_client).', $exception->getMessage());
            self::assertSame('invalid_client', $exception->apiErrorCode);
        }
    }

    public function testNonObjectOauthErrorBodyFallsBackToTheTransportMessage(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(400, [], 'null'));
        $provider = $this->provider($mock, $this->clientCredentials());

        $this->expectException(FgaTokenExchangeException::class);
        $provider->getAccessToken();
    }

    public function testNonJsonTokenResponseIsRejected(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], 'nope'));
        $provider = $this->provider($mock, $this->clientCredentials());

        try {
            $provider->getAccessToken();
            self::fail('Expected token exchange failure');
        } catch (FgaTokenExchangeException $exception) {
            self::assertSame('Token endpoint returned a non-JSON response.', $exception->getMessage());
        }
    }

    public function testNetworkFailureDuringTokenExchangeIsWrapped(): void
    {
        $provider = $this->provider(new ThrowingTokenClient(), $this->clientCredentials());

        try {
            $provider->getAccessToken();
            self::fail('Expected token exchange failure');
        } catch (FgaTokenExchangeException $exception) {
            self::assertSame(0, $exception->statusCode);
        }
    }

    public function testTokenExchangeRetriesServerErrors(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], '{"message":"down"}'));
        $mock->addResponse(new Response(200, [], '{"access_token":"tok","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials(), maxRetry: 1);

        self::assertSame('tok', $provider->getAccessToken());
        self::assertCount(2, $mock->getRequests());
    }

    public function testTokenExchangeRetriesNetworkErrors(): void
    {
        $mock = new MockClient();
        $mock->addException(new TestNetworkException('reset', (new Psr17Factory())->createRequest('POST', 'https://issuer.example/oauth/token')));
        $mock->addResponse(new Response(200, [], '{"access_token":"tok","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials(), maxRetry: 1);

        self::assertSame('tok', $provider->getAccessToken());
        self::assertCount(2, $mock->getRequests());
    }

    public function testInvalidResponseReportsTheConfiguredTokenPath(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], 'nope'));
        $provider = $this->provider($mock, new ClientCredentials('client', 'secret', 'https://issuer.example/custom/token', 'audience'));

        try {
            $provider->getAccessToken();
            self::fail('Expected token exchange failure');
        } catch (FgaTokenExchangeException $exception) {
            self::assertSame('/custom/token', $exception->endpoint);
        }
    }

    public function testCacheKeySeparatesScopesAndCredentialTypes(): void
    {
        $cache = new SimpleArrayCache();
        $cache->set(self::key(), json_encode(['token' => 'unscoped', 'expiresAt' => self::NOW + 3600], JSON_THROW_ON_ERROR), 3600);
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"scoped","expires_in":3600}'));
        $mock->addResponse(new Response(200, [], '{"access_token":"assertion","expires_in":3600}'));

        $scoped = $this->provider($mock, new ClientCredentials('client', 'secret', 'issuer.example', 'audience', scopes: 'read'), $cache);
        $assertion = $this->provider($mock, new ClientAssertion('client', RsaPrivateKeyFixture::pem(), 'issuer.example', 'audience'), $cache);

        self::assertSame('scoped', $scoped->getAccessToken());
        self::assertSame('assertion', $assertion->getAccessToken());
        self::assertSame('unscoped', $this->provider(new MockClient(), $this->clientCredentials(), $cache)->getAccessToken());
    }

    public function testOtherWorkersKeepTheValidTokenWhileARefreshIsInProgress(): void
    {
        $cache = new SimpleArrayCache();
        $cache->set(self::key(), json_encode(['token' => 'current', 'expiresAt' => self::NOW + 60, 'refreshAt' => self::NOW - 1], JSON_THROW_ON_ERROR), 60);
        $cache->set(self::key() . '.refresh', '1', 10);
        $mock = new MockClient();

        self::assertSame('current', $this->provider($mock, $this->clientCredentials(), $cache)->getAccessToken());
        self::assertCount(0, $mock->getRequests());
    }

    public function testAMemoryTokenIsKeptWhileAnotherWorkerRefreshes(): void
    {
        $cache = new SimpleArrayCache();
        $cache->set(self::key() . '.refresh', '1', 10);
        $mock = new MockClient();
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);
        (new \ReflectionProperty(TokenProvider::class, 'memoryToken'))->setValue($provider, new AccessToken('memory', self::NOW + 60, self::NOW - 1));

        self::assertSame('memory', $provider->getAccessToken());
        self::assertCount(0, $mock->getRequests());
    }

    public function testATokenDueForRefreshIsReplacedWhenNoRefreshIsInProgress(): void
    {
        $cache = new SimpleArrayCache();
        $cache->set(self::key(), json_encode(['token' => 'current', 'expiresAt' => self::NOW + 60, 'refreshAt' => self::NOW], JSON_THROW_ON_ERROR), 60);
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"next","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);

        self::assertSame('next', $provider->getAccessToken());
        self::assertSame('next', $provider->getAccessToken());
        self::assertCount(1, $mock->getRequests());
    }

    public function testAMemoryTokenDueForRefreshIsReplacedWithoutACache(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"next","expires_in":3600}'));
        $provider = $this->provider($mock, $this->clientCredentials());
        (new \ReflectionProperty(TokenProvider::class, 'memoryToken'))->setValue($provider, new AccessToken('memory', self::NOW + 60, self::NOW));

        self::assertSame('next', $provider->getAccessToken());
    }

    public function testTheRefreshMarkerIsHeldDuringTheFetchAndClearedAfterwards(): void
    {
        $cache = new SimpleArrayCache();
        $client = new MarkerObservingClient($cache, self::key() . '.refresh');
        $provider = $this->provider($client, $this->clientCredentials(), $cache);

        self::assertSame('observed', $provider->getAccessToken());
        self::assertSame([true], $client->markerSeen);
        self::assertSame(10, $cache->lastTtlSecondsFor(self::key() . '.refresh'));
        self::assertFalse($cache->has(self::key() . '.refresh'));
    }

    public function testTheRefreshMarkerIsClearedWhenTheFetchFails(): void
    {
        $cache = new SimpleArrayCache();
        $provider = $this->provider(new ThrowingTokenClient(), $this->clientCredentials(), $cache);

        try {
            $provider->getAccessToken();
            self::fail('Expected token exchange failure');
        } catch (FgaTokenExchangeException) {
            self::assertFalse($cache->has(self::key() . '.refresh'));
        }
    }

    public function testRefreshBufferDivisorsAreApplied(): void
    {
        self::assertSame(self::NOW + 89, $this->expiryFor(100));
        self::assertSame(self::NOW + 17, $this->expiryFor(20));
        self::assertSame(self::NOW + 68, $this->expiryFor(76));
        self::assertSame(self::NOW + 1, $this->expiryFor(1));
    }

    public function testZeroJitterDoesNotConsumeTheRandomizer(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"first","expires_in":19}'));
        $mock->addResponse(new Response(200, [], '{"access_token":"second","expires_in":20}'));
        $provider = $this->provider(
            $mock,
            $this->clientCredentials(),
            randomizer: new Randomizer(new Mt19937(2)),
        );

        self::assertSame('first', $provider->getAccessToken());
        $provider->invalidate();
        self::assertSame('second', $provider->getAccessToken());
        self::assertSame(self::NOW + 18, $this->memoryExpiry($provider));
    }

    public function testShortLivedTokenIsReusedUntilItExpires(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"short","expires_in":60}'));
        $provider = $this->provider($mock, $this->clientCredentials());

        self::assertSame('short', $provider->getAccessToken());
        self::assertSame('short', $provider->getAccessToken());
        self::assertCount(1, $mock->getRequests());
    }

    public function testInvalidateDropsTheCachedToken(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"one","expires_in":3600}'));
        $mock->addResponse(new Response(200, [], '{"access_token":"two","expires_in":3600}'));
        $cache = new SimpleArrayCache();
        $provider = $this->provider($mock, $this->clientCredentials(), $cache);

        self::assertSame('one', $provider->getAccessToken());
        $provider->invalidate();
        self::assertSame('two', $provider->getAccessToken());
        self::assertCount(2, $mock->getRequests());
    }

    public function testTokenRefreshIsObservable(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], '{"access_token":"fresh","expires_in":3600}'));
        $logger = new RecordingLogger();
        $dispatcher = new RecordingDispatcher();
        $provider = $this->provider(
            $mock,
            $this->clientCredentials(),
            telemetry: new SdkTelemetry($logger, $dispatcher),
        );

        self::assertSame('fresh', $provider->getAccessToken());
        self::assertInstanceOf(TokenRefreshed::class, $dispatcher->events[0]);
        self::assertSame('openfga.token_refresh', $logger->records[0][1]);
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
        ClientInterface $mock,
        ClientCredentials|ClientAssertion $credentials,
        ?SimpleArrayCache $cache = null,
        ?SdkTelemetry $telemetry = null,
        ?Randomizer $randomizer = null,
        int $maxRetry = 0,
    ): TokenProvider {
        $factories = new Psr17Factory();
        $randomizer ??= new Randomizer(new Mt19937(1));
        $retry = new RetryPolicy(
            $maxRetry,
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
            $randomizer,
            cache: $cache,
            telemetry: $telemetry ?? new SdkTelemetry(),
        );
    }

    private function expiryFor(int $expiresIn): int
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], sprintf('{"access_token":"t","expires_in":%d}', $expiresIn)));
        $provider = $this->provider($mock, $this->clientCredentials());
        $provider->getAccessToken();

        return $this->memoryExpiry($provider);
    }

    private function memoryExpiry(TokenProvider $provider): int
    {
        $memory = new \ReflectionProperty(TokenProvider::class, 'memoryToken');
        $token = $memory->getValue($provider);
        self::assertInstanceOf(AccessToken::class, $token);

        return $token->refreshAtEpoch;
    }

    private static function key(): string
    {
        return 'openfga_token_' . hash('sha256', 'client_secret|https://issuer.example|client|audience|');
    }
}

final class MarkerObservingClient implements ClientInterface
{
    /** @var list<bool> */
    public array $markerSeen = [];

    public function __construct(private readonly SimpleArrayCache $cache, private readonly string $markerKey) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->markerSeen[] = $this->cache->has($this->markerKey);

        return new Response(200, [], '{"access_token":"observed","expires_in":3600}');
    }
}

final class ThrowingTokenClient implements ClientInterface
{
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new TestNetworkException('reset', $request);
    }
}
