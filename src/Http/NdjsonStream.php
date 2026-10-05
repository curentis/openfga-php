<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Exception\FgaResponseDecodeException;
use Psr\Http\Message\StreamInterface;

final class NdjsonStream
{
    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public static function decode(
        StreamInterface $stream,
        int $chunkSize = 8192,
        string $method = 'POST',
        string $endpoint = '/stream',
        ?string $storeId = null,
    ): \Generator {
        $buffer = '';
        while (!$stream->eof()) {
            $read = $stream->read($chunkSize);
            if ($read === '') {
                break;
            }
            $buffer .= $read;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                // Dropping the newline by 0 bytes would rescan the same line forever.
                /** @infection-ignore-all */
                $buffer = substr($buffer, $newline + 1);
                if ($line === '') {
                    continue;
                }
                yield self::parseLine($line, $method, $endpoint, $storeId);
            }
        }

        $remaining = trim($buffer);
        if ($remaining !== '') {
            yield self::parseLine($remaining, $method, $endpoint, $storeId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseLine(string $line, string $method, string $endpoint, ?string $storeId): array
    {
        try {
            $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new FgaResponseDecodeException(
                sprintf('OpenFGA stream returned a non-JSON line (%s %s).', $method, $endpoint),
                $exception,
            );
        }
        if (!is_array($decoded)) {
            throw new FgaResponseDecodeException(sprintf('Expected JSON object in NDJSON line (%s %s).', $method, $endpoint));
        }

        /** @var array<string, mixed> $decoded */
        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $message = isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'Streamed API error';
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
            $status = isset($error['http_code']) && is_int($error['http_code']) ? $error['http_code'] : 500;

            throw (new ErrorMapper())->mapStreamError($method, $endpoint, $storeId, $status, $code, $message);
        }

        return $decoded;
    }
}
