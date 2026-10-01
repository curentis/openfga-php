<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Exception;

use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaApiRateLimitException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class FgaApiExceptionTest extends TestCase
{
    public function testFgaApiExceptionExposesMetadata(): void
    {
        $e = new FgaApiException(
            'failed',
            409,
            'conflict',
            'already exists',
            'req-1',
            'POST',
            '/stores/x/write',
            'store-x',
            ['x-request-id' => ['req-1']],
        );

        self::assertSame(409, $e->statusCode);
        self::assertSame('conflict', $e->apiErrorCode);
        self::assertSame('req-1', $e->requestId);
        self::assertSame('store-x', $e->storeId);
        self::assertSame(409, $e->getCode());
    }

    public function testAuthenticationExceptionExposesStatusCode(): void
    {
        $e = new FgaApiAuthenticationException('auth', 401, null, 'denied', null, 'GET', '/stores', null, []);
        self::assertSame(401, $e->statusCode);
        self::assertSame('denied', $e->apiErrorMessage);
    }

    public function testRateLimitExceptionStoresRetryAfter(): void
    {
        $e = new FgaApiRateLimitException('limit', 429, null, 'slow down', null, 'GET', '/stores', null, [], 5000);
        self::assertSame(5000, $e->retryAfterMs);
    }

    public function testNetworkExceptionStoresMethodAndEndpoint(): void
    {
        $e = new FgaNetworkException('network', 'POST', '/stores/x/check');
        self::assertSame('POST', $e->method);
        self::assertSame('/stores/x/check', $e->endpoint);
        self::assertSame(0, $e->getCode());
    }

    public function testValidationExceptionMessage(): void
    {
        $e = new FgaValidationException('bad input');
        self::assertSame('bad input', $e->getMessage());
    }
}
