<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/** @internal Response whose header line is returned exactly, including surrounding whitespace. */
final class HeaderLineResponse implements ResponseInterface
{
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly string $headerName,
        private readonly string $headerLine,
    ) {}

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        if (strcasecmp($name, $this->headerName) === 0) {
            return $this->headerLine;
        }

        return $this->inner->getHeaderLine($name);
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    #[\Override]
    public function withProtocolVersion(string $version): static
    {
        return new self($this->inner->withProtocolVersion($version), $this->headerName, $this->headerLine);
    }

    #[\Override]
    public function getHeaders(): array
    {
        return $this->inner->getHeaders();
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return $this->inner->hasHeader($name);
    }

    #[\Override]
    public function getHeader(string $name): array
    {
        return $this->inner->getHeader($name);
    }

    #[\Override]
    public function withHeader(string $name, $value): static
    {
        return new self($this->inner->withHeader($name, $value), $this->headerName, $this->headerLine);
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): static
    {
        return new self($this->inner->withAddedHeader($name, $value), $this->headerName, $this->headerLine);
    }

    #[\Override]
    public function withoutHeader(string $name): static
    {
        return new self($this->inner->withoutHeader($name), $this->headerName, $this->headerLine);
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        return $this->inner->getBody();
    }

    #[\Override]
    public function withBody(StreamInterface $body): static
    {
        return new self($this->inner->withBody($body), $this->headerName, $this->headerLine);
    }

    #[\Override]
    public function getStatusCode(): int
    {
        return $this->inner->getStatusCode();
    }

    #[\Override]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new self($this->inner->withStatus($code, $reasonPhrase), $this->headerName, $this->headerLine);
    }

    #[\Override]
    public function getReasonPhrase(): string
    {
        return $this->inner->getReasonPhrase();
    }
}
