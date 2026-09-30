<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Tests\Support\RsaPrivateKeyFixture;
use PHPUnit\Framework\TestCase;

final class ClientAssertionTest extends TestCase
{
    public function testRejectsUnsupportedAlgorithm(): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientAssertion(
            'client',
            RsaPrivateKeyFixture::pem(),
            'issuer.example',
            'aud',
            algorithm: 'HS256',
        );
    }

    public function testTokenEndpointAndDebugInfoHideKey(): void
    {
        $assertion = new ClientAssertion(
            'client-1',
            RsaPrivateKeyFixture::pem(),
            'issuer.example',
            'audience-1',
            keyId: 'kid-1',
        );

        self::assertSame('https://issuer.example/oauth/token', $assertion->tokenEndpoint());
        $debug = $assertion->__debugInfo();
        self::assertSame('***', $debug['privateKeyPem']);
        self::assertSame('kid-1', $debug['keyId']);
    }
}
