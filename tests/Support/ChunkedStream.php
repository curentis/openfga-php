<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** @internal */
final class ChunkedStream implements StreamInterface
{
    private int $offset = 0;

    public function __construct(
        private readonly string $data,
        private readonly int $chunkSize,
    ) {}

    public function __toString(): string
    {
        return $this->data;
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach(): mixed
    {
        return null;
    }

    #[\Override]
    public function getSize(): int
    {
        return strlen($this->data);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->offset;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->offset >= strlen($this->data);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void {}

    #[\Override]
    public function rewind(): void
    {
        $this->offset = 0;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        return 0;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->data, $this->offset, $this->chunkSize);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        return substr($this->data, $this->offset);
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
