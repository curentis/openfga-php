<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

final class FgaResponseDecodeException extends \UnexpectedValueException implements FgaException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
