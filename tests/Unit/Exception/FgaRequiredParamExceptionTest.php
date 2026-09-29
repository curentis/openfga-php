<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Exception;

use Curentis\OpenFga\Exception\FgaRequiredParamException;
use PHPUnit\Framework\TestCase;

final class FgaRequiredParamExceptionTest extends TestCase
{
    public function testExposesParamNameAndDefaultMessage(): void
    {
        $e = new FgaRequiredParamException('storeId');
        self::assertSame('storeId', $e->paramName);
        self::assertStringContainsString('storeId', $e->getMessage());
    }
}
