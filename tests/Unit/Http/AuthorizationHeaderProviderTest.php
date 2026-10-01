<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Credentials\NoCredentials;
use Curentis\OpenFga\Http\AuthorizationHeaderProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationHeaderProviderTest extends TestCase
{
    public function testApiTokenReturnsBearerHeader(): void
    {
        $provider = new AuthorizationHeaderProvider(new ApiToken('static-token'));
        self::assertSame('Bearer static-token', $provider->authorizationHeader());
    }

    public function testNoCredentialsReturnsNull(): void
    {
        $provider = new AuthorizationHeaderProvider(new NoCredentials());
        self::assertNull($provider->authorizationHeader());
    }

    public function testNoCredentialsIgnoresTokenResolver(): void
    {
        $provider = new AuthorizationHeaderProvider(
            new NoCredentials(),
            static fn(): string => 'should-not-be-used',
        );
        self::assertNull($provider->authorizationHeader());
    }

    public function testTokenResolverUsedForOAuthCredentials(): void
    {
        $provider = new AuthorizationHeaderProvider(
            new ClientCredentials('id', 'secret', 'issuer', 'aud'),
            static fn(): string => 'resolved-token',
        );
        self::assertSame('Bearer resolved-token', $provider->authorizationHeader());
    }

    public function testOAuthCredentialsWithoutResolverReturnNull(): void
    {
        $provider = new AuthorizationHeaderProvider(
            new ClientCredentials('id', 'secret', 'issuer', 'aud'),
        );
        self::assertNull($provider->authorizationHeader());
    }
}
