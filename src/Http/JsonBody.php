<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Exception\FgaValidationException;

/**
 * @internal
 */
final class JsonBody
{
    public static function encode(mixed $body): string
    {
        if ($body instanceof \JsonSerializable) {
            /** @var mixed $body */
            $body = $body->jsonSerialize();
        }

        if ($body === []) {
            return '{}';
        }

        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            throw new FgaValidationException(
                sprintf('Request body is not JSON-encodable: %s.', $exception->getMessage()),
                0,
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('Expected JSON object response.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
