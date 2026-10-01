<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Http\NdjsonStream;
use Curentis\OpenFga\Tests\Support\CallbackReadStream;
use Curentis\OpenFga\Tests\Support\ChunkedStream;
use Curentis\OpenFga\Tests\Support\EmptyReadStream;
use Curentis\OpenFga\Tests\Support\EmptyThenPayloadStream;
use PHPUnit\Framework\TestCase;

final class NdjsonStreamTest extends TestCase
{
    public function testDecodesAcrossSmallChunks(): void
    {
        $payload = '{"a":1}' . "\n" . '{"b":2}' . "\n";
        $stream = new ChunkedStream($payload, 2);
        $lines = iterator_to_array(NdjsonStream::decode($stream, 2));

        self::assertSame([['a' => 1], ['b' => 2]], $lines);
    }

    public function testEmptyReadEndsDecoding(): void
    {
        self::assertSame([], iterator_to_array(NdjsonStream::decode(new EmptyReadStream())));
    }

    public function testNonObjectJsonLineThrows(): void
    {
        $stream = new ChunkedStream('42' . "\n", 64);

        $this->expectException(\JsonException::class);
        iterator_to_array(NdjsonStream::decode($stream));
    }

    public function testSkipsBlankLinesAndTrailingPayloadWithoutNewline(): void
    {
        $payload = "\n" . '{"a":1}' . "\n\n" . '{"b":2}';
        $stream = new ChunkedStream($payload, 4);
        $lines = iterator_to_array(NdjsonStream::decode($stream));

        self::assertSame([['a' => 1], ['b' => 2]], $lines);
    }

    public function testStreamErrorWithoutMessageUsesDefaultText(): void
    {
        $payload = '{"error":{}}' . "\n";
        $stream = new ChunkedStream($payload, 64);

        $this->expectException(FgaApiException::class);
        $this->expectExceptionMessage('Streamed API error');
        iterator_to_array(NdjsonStream::decode($stream));
    }

    public function testDefaultChunkSizeReadsAFullLine(): void
    {
        $done = false;
        $stream = new CallbackReadStream(
            function (int $length) use (&$done): string {
                if ($done || $length !== 8192) {
                    $done = true;

                    return '';
                }
                $done = true;

                return '{"a":1,"b":2}' . "\n";
            },
            function () use (&$done): bool {
                return $done;
            },
        );

        self::assertSame([['a' => 1, 'b' => 2]], iterator_to_array(NdjsonStream::decode($stream)));
    }

    public function testEmptyReadStopsBeforeLaterBytes(): void
    {
        $stream = new EmptyThenPayloadStream('{"a":1}' . "\n");

        self::assertSame([], iterator_to_array(NdjsonStream::decode($stream, 8)));
    }

    public function testTrimsWhitespaceAndSkipsBlankLinesInOneChunk(): void
    {
        $payload = "\n" . "\x0b" . '{"a":1}' . "\n\n" . "\x0b" . '{"b":2}' . "\x0b";
        $lines = iterator_to_array(NdjsonStream::decode(new ChunkedStream($payload, strlen($payload))));

        self::assertSame([['a' => 1], ['b' => 2]], $lines);
    }

    public function testStreamErrorUsesInternalStatus(): void
    {
        $stream = new ChunkedStream('{"error":{"message":"nope"}}' . "\n", 64);

        try {
            iterator_to_array(NdjsonStream::decode($stream));
            self::fail('Expected a stream error');
        } catch (FgaApiException $exception) {
            self::assertSame(500, $exception->statusCode);
            self::assertSame('nope', $exception->getMessage());
        }
    }

    public function testErrorLineThrowsMidStream(): void
    {
        $payload = '{"ok":true}' . "\n" . '{"error":{"message":"nope"}}' . "\n";
        $stream = new ChunkedStream($payload, 3);

        $generator = NdjsonStream::decode($stream, 3);
        self::assertSame(['ok' => true], $generator->current());

        $this->expectException(FgaApiException::class);
        $this->expectExceptionMessage('nope');
        $generator->next();
    }
}
