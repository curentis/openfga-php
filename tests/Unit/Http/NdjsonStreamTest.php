<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Http\NdjsonStream;
use Curentis\OpenFga\Tests\Support\ChunkedStream;
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
