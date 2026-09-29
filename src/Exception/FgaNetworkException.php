<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

/** @internal */
final class FgaNetworkException extends \RuntimeException implements FgaException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $endpoint,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
