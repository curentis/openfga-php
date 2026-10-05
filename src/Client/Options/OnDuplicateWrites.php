<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Client\Options;

enum OnDuplicateWrites: string
{
    case Error = 'error';
    case Ignore = 'ignore';
}
