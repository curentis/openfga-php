<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\ClientCredentials;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientCredentialsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function emptyFieldProvider(): iterable
    {
        yield 'clientId' => ['', 'secret', 'issuer', 'aud'];
        yield 'clientSecret' => ['id', '', 'issuer', 'aud'];
        yield 'issuer' => ['id', 'secret', '', 'aud'];
        yield 'audience' => ['id', 'secret', 'issuer', ''];
    }

    #[DataProvider('emptyFieldProvider')]
    public function testRejectsEmptyFields(string $clientId, string $secret, string $issuer, string $audience): void
    {
        $this->expectException(FgaValidationException::class);
        new ClientCredentials($clientId, $secret, $issuer, $audience);
    }

    public function testTokenEndpointAndDebugInfo(): void
    {
        $credentials = new ClientCredentials('client-1', 'secret-1', 'issuer.example', 'audience-1', scopes: 'read');
        self::assertSame('https://issuer.example/oauth/token', $credentials->tokenEndpoint());
        $debug = $credentials->__debugInfo();
        self::assertSame('client-1', $debug['clientId']);
        self::assertSame('***', $debug['clientSecret']);
        self::assertSame('read', $debug['scopes']);
    }
}
