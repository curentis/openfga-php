<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** @internal First read is empty while the stream is still open, then a payload follows. */
final class EmptyThenPayloadStream implements StreamInterface
{
    private int $reads = 0;

    public function __construct(private readonly string $payload) {}

    #[\Override]
    public function read(int $length): string
    {
        $this->reads++;
        if ($this->reads === 1) {
            return '';
        }

        return $this->payload;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->reads > 1;
    }

    #[\Override]
    public function __toString(): string
    {
        return '';
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach(): mixed
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return 0;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void {}

    #[\Override]
    public function rewind(): void {}

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
    public function getContents(): string
    {
        return '';
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
