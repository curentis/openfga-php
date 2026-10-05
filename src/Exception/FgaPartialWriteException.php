<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Exception;

use Curentis\OpenFga\Client\Response\ClientWriteTupleResult;

final class FgaPartialWriteException extends \RuntimeException implements FgaException
{
    /**
     * @param list<ClientWriteTupleResult> $completed tuple results committed before the failing chunk
     */
    public function __construct(
        public readonly array $completed,
        \Throwable $reason,
    ) {
        parent::__construct(
            'Non-transactional write stopped after a request failed for a reason other than tuple validation.',
            0,
            $reason,
        );
    }
}
