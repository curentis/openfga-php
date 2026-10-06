<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Psr\Http\Message\ResponseInterface;

interface RetryPolicyInterface
{
    /**
     * Implementations must not keep per-call state on the instance: the token provider calls the
     * same policy from inside a transport retry, and long-running runtimes may interleave calls.
     *
     * @param callable(): ResponseInterface $send builds and sends a fresh request on every attempt
     *
     * @throws \Curentis\OpenFga\Exception\FgaException
     */
    public function send(callable $send, RequestContext $context, ?RetryOptions $retry = null): ResponseInterface;
}
