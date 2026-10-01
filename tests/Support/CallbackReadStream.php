<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\Http\Message\StreamInterface;

/** @internal */
final class CallbackReadStream implements StreamInterface
{
    /**
     * @param \Closure(int): string $read
     * @param \Closure(): bool      $eof
     */
    public function __construct(
        private readonly \Closure $read,
        private readonly \Closure $eof,
    ) {}

    #[\Override]
    public function read(int $length): string
    {
        return ($this->read)($length);
    }

    #[\Override]
    public function eof(): bool
    {
        return ($this->eof)();
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
