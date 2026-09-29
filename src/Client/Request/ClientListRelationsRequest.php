<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Request;

use Curentis\OpenFga\Exception\FgaValidationException;

final readonly class ClientListRelationsRequest
{
    /**
     * @param list<string> $relations
     */
    public function __construct(
        public string $user,
        public string $object,
        public array $relations,
    ) {
        if ($relations === []) {
            throw new FgaValidationException('relations must not be empty for listRelations.');
        }
    }
}
