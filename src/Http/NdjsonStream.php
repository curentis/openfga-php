<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Exception\FgaApiException;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
final class NdjsonStream
{
    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public static function decode(StreamInterface $stream, int $chunkSize = 8192): \Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $read = $stream->read($chunkSize);
            if ($read === '') {
                break;
            }
            $buffer .= $read;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    continue;
                }
                yield self::parseLine($line);
            }
        }

        $remaining = trim($buffer);
        if ($remaining !== '') {
            yield self::parseLine($remaining);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseLine(string $line): array
    {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('Expected JSON object in NDJSON line.');
        }

        /** @var array<string, mixed> $decoded */
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = isset($decoded['error']['message']) && is_string($decoded['error']['message'])
                ? $decoded['error']['message']
                : 'Streamed API error';
            throw new FgaApiException(
                $message,
                500,
                null,
                $message,
                null,
                'POST',
                '/stream',
                null,
                [],
            );
        }

        return $decoded;
    }
}
