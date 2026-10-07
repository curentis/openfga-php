<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\ClientAssertion;
use Curentis\OpenFga\Credentials\ClientAssertionJwt;
use Curentis\OpenFga\Credentials\IssuerUrl;
use Curentis\OpenFga\Exception\FgaValidationException;
use Curentis\OpenFga\Tests\Support\RsaPrivateKeyFixture;
use PHPUnit\Framework\TestCase;

final class ClientAssertionJwtTest extends TestCase
{
    public function testSignProducesThreeSegmentJwtWithOptionalKid(): void
    {
        $credentials = new ClientAssertion(
            'client-1',
            RsaPrivateKeyFixture::pem(),
            'issuer.example',
            'audience-1',
            keyId: 'key-1',
        );

        $issuedAt = 1_700_000_000;
        $jwt = ClientAssertionJwt::sign($credentials, $issuedAt, 'jti-123');
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        $header = $this->decodeJwtPart($parts[0]);
        self::assertSame('RS256', $header['alg']);
        self::assertSame('key-1', $header['kid']);

        $payload = $this->decodeJwtPart($parts[1]);
        self::assertSame('client-1', $payload['iss']);
        self::assertSame('client-1', $payload['sub']);
        self::assertSame(IssuerUrl::audienceForJwt('issuer.example'), $payload['aud']);
        self::assertSame('jti-123', $payload['jti']);
        self::assertSame($issuedAt, $payload['iat']);
        self::assertSame($issuedAt + 300, $payload['exp']);
        foreach ($parts as $segment) {
            self::assertDoesNotMatchRegularExpression('/=/', $segment);
        }
    }

    public function testEmptyKeyIdIsOmittedFromHeader(): void
    {
        $credentials = new ClientAssertion(
            'client-3',
            RsaPrivateKeyFixture::pem(),
            'issuer.example',
            'audience-3',
            keyId: '',
        );

        $jwt = ClientAssertionJwt::sign($credentials, 1_700_000_000, 'jti-789');
        $header = $this->decodeJwtPart(explode('.', $jwt)[0]);
        self::assertArrayNotHasKey('kid', $header);
    }

    public function testSignWithoutKidOmitsHeaderKid(): void
    {
        $credentials = new ClientAssertion(
            'client-2',
            RsaPrivateKeyFixture::pem(),
            'issuer.example',
            'audience-2',
        );

        $jwt = ClientAssertionJwt::sign($credentials, 1_700_000_000, 'jti-456');
        $header = $this->decodeJwtPart(explode('.', $jwt)[0]);
        self::assertArrayNotHasKey('kid', $header);
    }

    public function testRequireNonEmptyBinaryStringRejectsEmptyValue(): void
    {
        $method = new \ReflectionMethod(ClientAssertionJwt::class, 'requireNonEmptyBinaryString');

        $this->expectException(FgaValidationException::class);
        $this->expectExceptionMessage('empty client assertion signature');
        $method->invoke(null, '');
    }

    public function testInvalidPrivateKeyThrows(): void
    {
        $this->expectException(\Curentis\OpenFga\Exception\FgaValidationException::class);
        $this->expectExceptionMessage('RSA private key');
        new ClientAssertion('client', 'not-a-key', 'issuer.example', 'audience');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPart(string $segment): array
    {
        $padding = str_repeat('=', (4 - strlen($segment) % 4) % 4);
        $decoded = base64_decode(strtr($segment, '-_', '+/') . $padding, true);
        self::assertIsString($decoded);

        /** @var array<string, mixed> $header */
        $header = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);

        return $header;
    }
}
