<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Observability;

enum RequestOutcome: string
{
    case Success = 'success';
    case HttpError = 'http_error';
    case NetworkError = 'network_error';
}
