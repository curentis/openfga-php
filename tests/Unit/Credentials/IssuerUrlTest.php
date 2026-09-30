<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\IssuerUrl;
use PHPUnit\Framework\TestCase;

final class IssuerUrlTest extends TestCase
{
    public function testNormalizeAddsHttpsAndTrimsSlashes(): void
    {
        self::assertSame('https://issuer.example', IssuerUrl::normalize('issuer.example/'));
        self::assertSame('https://issuer.example', IssuerUrl::normalize('https://issuer.example'));
        self::assertSame('http://issuer.example', IssuerUrl::normalize('http://issuer.example/'));
        self::assertSame('HTTP://UPPER.example', IssuerUrl::normalize('HTTP://UPPER.example'));
        self::assertSame(
            'https://foo.com/http://bar.com',
            IssuerUrl::normalize('foo.com/http://bar.com'),
        );
    }

    public function testNormalizeRejectsEmptyIssuer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IssuerUrl::normalize('   ');
    }

    public function testTokenEndpointAppendsOAuthPathWhenRoot(): void
    {
        self::assertSame(
            'https://issuer.example/oauth/token',
            IssuerUrl::tokenEndpoint('issuer.example'),
        );
    }

    public function testTokenEndpointPreservesCustomPath(): void
    {
        self::assertSame(
            'https://issuer.example/custom/token',
            IssuerUrl::tokenEndpoint('https://issuer.example/custom/token'),
        );
    }

    public function testAudienceForJwtEndsWithSlash(): void
    {
        self::assertSame('https://issuer.example/', IssuerUrl::audienceForJwt('issuer.example'));
    }
}
