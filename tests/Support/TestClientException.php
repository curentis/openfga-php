<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;

final class TestClientException extends \RuntimeException implements ClientExceptionInterface {}
