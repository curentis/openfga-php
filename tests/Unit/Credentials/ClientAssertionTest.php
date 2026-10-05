<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Tests\Support\RsaPrivateKeyFixture;
use PHPUnit\Framework\TestCase;

final class ClientAssertionTest extends TestCase
{
    public function testRejectsEmptyClientId(): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientAssertion('', RsaPrivateKeyFixture::pem(), 'issuer.example', 'aud');
    }

    public function testRejectsEmptyPrivateKey(): void
    {
        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('privateKeyPem must not be empty.');
        new ClientAssertion('client', '', 'issuer.example', 'aud');
    }

    public function testRejectsEmptyIssuer(): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientAssertion('client', RsaPrivateKeyFixture::pem(), '', 'aud');
    }

    public function testRejectsEmptyAudience(): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientAssertion('client', RsaPrivateKeyFixture::pem(), 'issuer.example', '');
    }

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

    public function testRejectsNonRsaKeys(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem));
        // openssl_pkey_export's by-ref output is mixed for PHPStan and string for Psalm.
        /** @psalm-suppress TypeDoesNotContainType */
        if (!is_string($pem) || $pem === '') {
            self::fail('openssl_pkey_export did not write a PEM string.');
        }

        $this->expectException(FgaValidationException::class);
        new ClientAssertion('client', $pem, 'https://issuer.example', 'audience');
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
        self::assertSame('client-1', $debug['clientId']);
        self::assertSame('***', $debug['privateKeyPem']);
        self::assertSame('issuer.example', $debug['apiTokenIssuer']);
        self::assertSame('audience-1', $debug['apiAudience']);
        self::assertSame('RS256', $debug['algorithm']);
        self::assertSame('kid-1', $debug['keyId']);
    }
}
