<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testV4MatchesUuidFormat(): void
    {
        for ($i = 0; $i < 32; ++$i) {
            $uuid = Uuid::v4();
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid,
            );

            $bytes = hex2bin(str_replace('-', '', $uuid));
            self::assertIsString($bytes);
            self::assertSame(16, strlen($bytes));
            self::assertSame(0x40, ord($bytes[6]) & 0xf0);
            self::assertSame(0x80, ord($bytes[8]) & 0xc0);
        }
    }
}
