<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Exception;

use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use PHPUnit\Framework\TestCase;

final class FgaApiSubclassTest extends TestCase
{
    public function testInternalExceptionPreservesApiFields(): void
    {
        $e = new FgaApiInternalException('internal', 500, 'internal_error', 'boom', 'req', 'GET', '/stores', null, []);
        self::assertSame(500, $e->statusCode);
        self::assertSame('internal_error', $e->apiErrorCode);
    }

    public function testNotFoundExceptionPreservesEndpoint(): void
    {
        $e = new FgaApiNotFoundException('missing', 404, null, 'not found', null, 'GET', '/stores/x', 'x', []);
        self::assertSame('/stores/x', $e->endpoint);
        self::assertSame('x', $e->storeId);
    }

    public function testValidationExceptionPreservesMessageField(): void
    {
        $e = new FgaApiValidationException('invalid', 400, 'validation_error', 'bad tuple', null, 'POST', '/check', null, []);
        self::assertSame('bad tuple', $e->apiErrorMessage);
    }
}
