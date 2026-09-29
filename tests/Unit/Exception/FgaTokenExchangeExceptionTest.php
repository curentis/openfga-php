<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Exception;

use Curentis\OpenFga\Exception\FgaTokenExchangeException;
use PHPUnit\Framework\TestCase;

final class FgaTokenExchangeExceptionTest extends TestCase
{
    public function testDebugInfoOmitsSecrets(): void
    {
        $e = new FgaTokenExchangeException(
            'token exchange failed',
            401,
            'invalid_client',
            'bad credentials',
            null,
            'POST',
            '/oauth/token',
            null,
            [],
            'https://issuer.example',
            'audience',
            'client-id-1',
        );

        self::assertSame('client-id-1', $e->clientId);
        $debug = $e->__debugInfo();
        self::assertArrayNotHasKey('apiErrorMessage', $debug);
        self::assertStringNotContainsString('secret', strtolower((string) json_encode($debug)));
    }
}
