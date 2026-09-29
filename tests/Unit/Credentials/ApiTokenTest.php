<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\ApiToken;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase
{
    public function testRejectsEmptyToken(): void
    {
        $this->expectException(FgaValidationException::class);
        new ApiToken('');
    }

    public function testDebugInfoHidesToken(): void
    {
        $token = new ApiToken('secret');
        self::assertSame(['token' => '***'], $token->__debugInfo());
    }
}
