<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Client;

use Curentis\OpenFga\Client\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testFormatV4BytesAppliesRfc4122VersionAndVariantBits(): void
    {
        $bytes = hex2bin('ffeeddccbbaa99887766555443322100');
        self::assertIsString($bytes);

        self::assertSame(
            'ffeeddcc-bbaa-4988-b766-555443322100',
            Uuid::formatV4Bytes($bytes),
        );
    }

    public function testFormatV4BytesRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Uuid::formatV4Bytes('short');
    }

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
            self::assertSame(4, (ord($bytes[6]) >> 4) & 0x0f);
            self::assertSame(2, (ord($bytes[8]) >> 6) & 0x03);
            self::assertSame('4', $uuid[14]);
            self::assertMatchesRegularExpression('/^[89ab]$/', $uuid[19]);
            self::assertSame(36, strlen($uuid));
        }
    }
}
