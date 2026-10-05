<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\IssuerUrl;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class IssuerUrlTest extends TestCase
{
    public function testNormalizeAddsHttpsAndTrimsSlashes(): void
    {
        self::assertSame('https://issuer.example', IssuerUrl::normalize('issuer.example/'));
        self::assertSame('https://issuer.example', IssuerUrl::normalize('https://issuer.example'));
        self::assertSame('http://localhost', IssuerUrl::normalize('http://localhost/'));
        self::assertSame('HTTPS://UPPER.example', IssuerUrl::normalize('HTTPS://UPPER.example'));
        self::assertSame(
            'https://foo.com/http://bar.com',
            IssuerUrl::normalize('foo.com/http://bar.com'),
        );
    }

    public function testNormalizeRejectsEmptyIssuer(): void
    {
        $this->expectException(FgaValidationException::class);
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

    public function testRejectsPublicHttpIssuers(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('https');
        IssuerUrl::normalize('http://issuer.example');
    }

    public function testAllowsLoopbackHttp(): void
    {
        self::assertSame('http://127.0.0.1', IssuerUrl::normalize('http://127.0.0.1'));
        self::assertSame('http://[::1]', IssuerUrl::normalize('http://[::1]'));
        self::assertSame('http://LOCALHOST', IssuerUrl::normalize('http://LOCALHOST'));
    }

    public function testRejectsUppercasePublicHttp(): void
    {
        $this->expectException(FgaValidationException::class);
        IssuerUrl::normalize('HTTP://issuer.example');
    }

    public function testAudienceForJwtEndsWithSlash(): void
    {
        self::assertSame('https://issuer.example/', IssuerUrl::audienceForJwt('issuer.example'));
    }
}
