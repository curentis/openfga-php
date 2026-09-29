<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Response;

use Curentis\OpenFga\Model\WriteResponse;

final readonly class ClientWriteResponse
{
    public function __construct(public WriteResponse $response) {}
}
