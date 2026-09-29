<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Support;

use Nyholm\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class TestNetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly ?RequestInterface $request = null,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request ?? new Request('GET', 'https://example.test/');
    }
}
