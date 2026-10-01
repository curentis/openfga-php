<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Tests\Unit\Http;

use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaApiInternalException;
use Curentis\OpenFga\Exception\FgaApiNotFoundException;
use Curentis\OpenFga\Exception\FgaApiRateLimitException;
use Curentis\OpenFga\Exception\FgaApiValidationException;
use Curentis\OpenFga\Http\ErrorMapper;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    private ErrorMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        $this->mapper = new ErrorMapper();
    }

    /**
     * @return iterable<string, array{0: int, 1: class-string}>
     */
    public static function statusToExceptionClass(): iterable
    {
        yield '400 validation' => [400, FgaApiValidationException::class];
        yield '401 authentication' => [401, FgaApiAuthenticationException::class];
        yield '403 authentication' => [403, FgaApiAuthenticationException::class];
        yield '404 not found' => [404, FgaApiNotFoundException::class];
        yield '422 validation' => [422, FgaApiValidationException::class];
        yield '429 rate limit' => [429, FgaApiRateLimitException::class];
        yield '500 internal' => [500, FgaApiInternalException::class];
        yield '501 internal' => [501, FgaApiInternalException::class];
        yield '503 internal' => [503, FgaApiInternalException::class];
        yield '409 unknown 4xx' => [409, FgaApiException::class];
    }

    #[DataProvider('statusToExceptionClass')]
    public function testMapsHttpStatusToExceptionType(int $status, string $expectedClass): void
    {
        self::assertTrue(class_exists($expectedClass));
        $response = new Response($status, [], '{"code":"validation_error","message":"bad input"}');
        $exception = $this->mapper->map('POST', '/stores/abc/check', 'abc', $response);

        self::assertSame($expectedClass, $exception::class);
        self::assertSame($status, $exception->statusCode);
        self::assertSame('POST', $exception->method);
        self::assertSame('/stores/abc/check', $exception->endpoint);
        self::assertSame('abc', $exception->storeId);
    }

    public function testUsesRawBodyWhenJsonMessageFieldIsMissing(): void
    {
        $response = new Response(500, [], '{"code":"internal"}');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertSame('internal', $exception->apiErrorCode);
        self::assertSame('{"code":"internal"}', $exception->apiErrorMessage);
    }

    public function testParsesApiErrorCodeAndMessageFromJsonBody(): void
    {
        $response = new Response(400, [], '{"code":"validation_error","message":"tuple key is invalid"}');
        $exception = $this->mapper->map('POST', '/stores/x/check', 'x', $response);

        self::assertSame('validation_error', $exception->apiErrorCode);
        self::assertSame('tuple key is invalid', $exception->apiErrorMessage);
        self::assertStringContainsString('OpenFGA API request failed', $exception->getMessage());
        self::assertStringContainsString('validation_error', $exception->getMessage());
        self::assertStringContainsString('tuple key is invalid', $exception->getMessage());
    }

    public function testNonJsonBodyUsesRawTextAsApiMessage(): void
    {
        $response = new Response(500, [], 'upstream exploded');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertNull($exception->apiErrorCode);
        self::assertSame('upstream exploded', $exception->apiErrorMessage);
    }

    public function testExtractsRequestIdHeader(): void
    {
        $response = new Response(404, ['X-Request-Id' => ['req-123']], '{"message":"not found"}');
        $exception = $this->mapper->map('GET', '/stores/missing', null, $response);

        self::assertSame('req-123', $exception->requestId);
    }

    public function testRateLimitExceptionCarriesRetryAfterMs(): void
    {
        $response = new Response(429, [], '{"message":"too many requests"}');
        $exception = $this->mapper->map('POST', '/stores/s/check', 's', $response, retryAfterMs: 3000);

        self::assertInstanceOf(FgaApiRateLimitException::class, $exception);
        self::assertSame(3000, $exception->retryAfterMs);
    }

    public function testEmptyApiErrorCodeIsOmittedFromFormattedMessage(): void
    {
        $response = new Response(400, [], '{"code":"","message":"bad"}');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertSame('', $exception->apiErrorCode);
        self::assertStringNotContainsString('API code:', $exception->getMessage());
    }

    public function testEmptyResponseBodyMapsToMinimalMessage(): void
    {
        $response = new Response(400, [], '');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertNull($exception->apiErrorCode);
        self::assertSame('', $exception->apiErrorMessage);
    }

    public function testJsonNullBodyFallsBackToRawText(): void
    {
        $response = new Response(500, [], 'null');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertNull($exception->apiErrorCode);
        self::assertSame('null', $exception->apiErrorMessage);
        self::assertInstanceOf(FgaApiInternalException::class, $exception);
    }

    public function testMapsOther4xxToBaseApiException(): void
    {
        $response = new Response(402, [], '{"message":"payment required"}');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertSame(402, $exception->statusCode);
        self::assertSame('payment required', $exception->apiErrorMessage);
    }

    public function testMaps5xxOutsideKnownListToInternalException(): void
    {
        $response = new Response(599, [], '{"message":"gateway"}');
        $exception = $this->mapper->map('GET', '/stores', null, $response);

        self::assertInstanceOf(FgaApiInternalException::class, $exception);
    }

    public function testFgaQueryIdHeaderIsUsedAsRequestId(): void
    {
        $response = new Response(500, ['Fga-Query-Id' => ['query-99'], 'X-Trace' => ['abc']], '{"message":"err"}');
        $exception = $this->mapper->map('POST', '/stores/s/check', 's', $response);

        self::assertSame('query-99', $exception->requestId);
        self::assertSame(['query-99'], $exception->responseHeaders['fga-query-id'] ?? null);
        self::assertSame(['abc'], $exception->responseHeaders['x-trace'] ?? null);
    }

    public function testExceptionMessageNeverContainsResponseBodySecrets(): void
    {
        $response = new Response(401, [], '{"message":"unauthorized","client_secret":"leak"}');
        $exception = $this->mapper->map('POST', '/stores/s/check', 's', $response);

        self::assertStringNotContainsString('leak', $exception->getMessage());
        self::assertStringNotContainsString('client_secret', $exception->getMessage());
    }
}
