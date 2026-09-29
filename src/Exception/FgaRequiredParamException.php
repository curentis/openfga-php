<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

final class FgaRequiredParamException extends \InvalidArgumentException implements FgaException
{
    public function __construct(
        public readonly string $paramName,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf('Required parameter "%s" is missing.', $paramName));
    }
}
