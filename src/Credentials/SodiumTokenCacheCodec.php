<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaValidationException;

final class SodiumTokenCacheCodec implements TokenCacheCodec
{
    private readonly JsonTokenCacheCodec $json;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new FgaValidationException(sprintf(
                'tokenCacheKey must be %d bytes.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }
        $this->json = new JsonTokenCacheCodec();
    }

    #[\Override]
    public function encode(string $accessToken, int $expiresAtEpoch): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($this->json->encode($accessToken, $expiresAtEpoch), $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    #[\Override]
    public function decode(string $payload): ?array
    {
        $raw = base64_decode($payload, true);
        // An empty ciphertext also fails secretbox_open, so `<=` and `<` both yield null.
        /** @infection-ignore-all */
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            return null;
        }

        return $this->json->decode($plain);
    }
}
