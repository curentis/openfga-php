<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

final class RsaPrivateKeyFixture
{
    public static function pem(): string
    {
        $path = __DIR__ . '/Fixtures/rsa_private.pem';
        $pem = file_get_contents($path);
        if ($pem === false || $pem === '') {
            throw new \RuntimeException('Missing RSA private key fixture at ' . $path);
        }

        return $pem;
    }
}
