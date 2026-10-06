<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

enum OnMissingDeletes: string
{
    case Error = 'error';
    case Ignore = 'ignore';
}
