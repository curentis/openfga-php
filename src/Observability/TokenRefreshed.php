<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Observability;

final class TokenRefreshed
{
    public function __construct(public readonly string $clientId) {}
}
