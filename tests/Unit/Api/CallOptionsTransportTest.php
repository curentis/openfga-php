<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Api;

use Curentis\OpenFga\Api\CallOptionsTransport;
use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Http\TransportInterface;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CallOptionsTransportTest extends TestCase
{
    public function testCallSiteRetryOverridesTheWrappedOptions(): void
    {
        $inner = new RecordingTransport();
        $wrapped = new RetryOptions(maxRetry: 1, minWaitMs: 1);
        $transport = new CallOptionsTransport($inner, $wrapped);
        $override = new RetryOptions(maxRetry: 4, minWaitMs: 2);

        $transport->send('GET', '/stores');
        self::assertTrue($inner->idempotent);

        $transport->send('GET', '/stores', retry: $override, idempotent: false);
        self::assertSame($override, $inner->retry);
        self::assertFalse($inner->idempotent);

        $transport->sendJson('POST', '/stores', retry: null, idempotent: true);
        self::assertSame($wrapped, $inner->retry);
        self::assertTrue($inner->idempotent);

    }
}

final class RecordingTransport implements TransportInterface
{
    public ?RetryOptions $retry = null;

    public bool $idempotent = true;

    #[\Override]
    public function send(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
        ?string $storeId = null,
        ?RetryOptions $retry = null,
        bool $idempotent = true,
    ): ResponseInterface {
        $this->retry = $retry;
        $this->idempotent = $idempotent;

        return new Response(200, [], '{}');
    }

    #[\Override]
    public function sendJson(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
        ?string $storeId = null,
        ?RetryOptions $retry = null,
        bool $idempotent = true,
    ): array {
        $this->retry = $retry;
        $this->idempotent = $idempotent;

        return [];
    }
}
