<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Credentials;

use Curentis\OpenFga\Credentials\JsonTokenCacheCodec;
use Curentis\OpenFga\Credentials\SodiumTokenCacheCodec;
use Curentis\OpenFga\Exception\FgaValidationException;
use PHPUnit\Framework\TestCase;

final class TokenCacheCodecTest extends TestCase
{
    public function testJsonCodecRoundTripsAndRejectsMalformedPayloads(): void
    {
        $codec = new JsonTokenCacheCodec();
        $encoded = $codec->encode('token', 123);
        self::assertSame(['token', 123], $codec->decode($encoded));
        self::assertSame(['token', 123], $codec->decode('{"token":"token","expiresAt":"123"}'));
        self::assertNull($codec->decode('not-json'));
        self::assertNull($codec->decode('[]'));
        self::assertNull($codec->decode('{"token":1,"expiresAt":1}'));
        self::assertNull($codec->decode('{"token":"token","expiresAt":"12a"}'));
    }

    public function testSodiumCodecRoundTripsAndRejectsTamperedPayloads(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $codec = new SodiumTokenCacheCodec($key);
        $encoded = $codec->encode('token', 99);
        self::assertSame(['token', 99], $codec->decode($encoded));
        self::assertNull($codec->decode('@@@'));
        self::assertNull($codec->decode(base64_encode('short')));

        $other = new SodiumTokenCacheCodec(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        self::assertNull($other->decode($encoded));

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox('not-json', $nonce, $key);
        self::assertNull($codec->decode(base64_encode($nonce . $cipher)));
    }

    public function testSodiumCodecRejectsAShortKey(): void
    {
        $this->expectException(FgaValidationException::class);
        new SodiumTokenCacheCodec('short');
    }
}
