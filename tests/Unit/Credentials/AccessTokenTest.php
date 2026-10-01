<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\AccessToken;
use PHPUnit\Framework\TestCase;

final class AccessTokenTest extends TestCase
{
    public function testIsExpiredAtComparesEpoch(): void
    {
        $token = new AccessToken('abc', 100);

        self::assertFalse($token->isExpiredAt(99));
        self::assertTrue($token->isExpiredAt(100));
        self::assertTrue($token->isExpiredAt(101));
    }
}
