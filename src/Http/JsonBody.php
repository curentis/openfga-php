<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

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

        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
