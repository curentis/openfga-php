<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaResponseDecodeException;
use Curentis\OpenFga\Observability\RequestOutcome;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Curentis\OpenFga\Version;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

final class Transport implements ParallelTransportInterface
{
    private readonly string $basePath;

    /**
     * @param array<string, string> $defaultHeaders
     * @param ?ConcurrentSenderInterface $concurrentSender enables `sendJsonAll()` concurrency; null sends sequentially
     */
    public function __construct(
        private readonly string $apiUrl,
        private readonly array $defaultHeaders,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly RetryPolicyInterface $retryPolicy,
        private readonly AuthorizationHeaderProvider $authorizationHeaderProvider,
        private readonly ?ConcurrentSenderInterface $concurrentSender = null,
        private readonly ErrorMapper $errorMapper = new ErrorMapper(),
        private readonly SdkTelemetry $telemetry = new SdkTelemetry(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        $path = parse_url($apiUrl, PHP_URL_PATH);
        $this->basePath = is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     */
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
        $context = $this->context($method, $pathTemplate, $pathParams, $storeId, $idempotent);
        $refreshed = false;

        while (true) {
            try {
                return $this->retryPolicy->send(
                    fn(): ResponseInterface => $this->httpClient->sendRequest(
                        $this->buildRequest($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders),
                    ),
                    $context,
                    $retry,
                );
            } catch (FgaApiAuthenticationException $exception) {
                if ($exception->statusCode !== 401 || $refreshed || !$this->authorizationHeaderProvider->invalidate()) {
                    throw $exception;
                }
                $refreshed = true;
            }
        }
    }

    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     *
     * @return array<string, mixed>
     */
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
        $response = $this->send($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders, $storeId, $retry, $idempotent);

        return $this->decodeJson($response, $this->context($method, $pathTemplate, $pathParams, $storeId, $idempotent));
    }

    /**
     * A call whose concurrent attempt fails with a status worth retrying, or with a transport error on
     * an idempotent call, is sent again through `sendJson()`, where the retry policy and 401 refresh apply.
     *
     * @param list<TransportCall> $calls
     * @param positive-int        $maxParallel
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function sendJsonAll(array $calls, int $maxParallel, ?RetryOptions $retry = null): array
    {
        if ($this->concurrentSender === null || $maxParallel === 1) {
            return array_map(fn(TransportCall $call): array => $this->sendCall($call, $retry), $calls);
        }

        $requests = array_map(
            fn(TransportCall $call): RequestInterface => $this->buildRequest(
                $call->method,
                $call->pathTemplate,
                $call->pathParams,
                $call->query,
                $call->body,
                $call->headers,
            ),
            $calls,
        );
        $startedMs = $this->nowMs();
        $outcomes = $this->concurrentSender->send($requests, $maxParallel);
        $durationMs = $this->nowMs() - $startedMs;

        $decoded = [];
        foreach ($calls as $index => $call) {
            $decoded[] = $this->resolveConcurrent($call, $outcomes[$index] ?? null, $durationMs, $retry);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConcurrent(
        TransportCall $call,
        ResponseInterface|\Throwable|null $outcome,
        int $durationMs,
        ?RetryOptions $retry,
    ): array {
        $context = $this->context($call->method, $call->pathTemplate, $call->pathParams, $call->storeId, $call->idempotent);

        if ($outcome instanceof ResponseInterface) {
            $status = $outcome->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $this->telemetry->requestFinished($context, $status, 1, $durationMs, RequestOutcome::Success);

                return $this->decodeJson($outcome, $context);
            }
            if (!$this->worthResending($status, $call->idempotent)) {
                $this->telemetry->requestFinished($context, $status, 1, $durationMs, RequestOutcome::HttpError);

                throw $this->errorMapper->map(
                    $call->method,
                    $context->endpoint,
                    $call->storeId,
                    $outcome,
                    RetryAfter::delayMs($outcome, $this->clock),
                );
            }

            return $this->sendCall($call, $retry);
        }

        if (!$call->idempotent) {
            $this->telemetry->requestFinished($context, null, 1, $durationMs, RequestOutcome::NetworkError);

            throw new FgaNetworkException(
                sprintf('OpenFGA API request failed (%s %s).', $call->method, $context->endpoint),
                $call->method,
                $context->endpoint,
                $outcome,
            );
        }

        return $this->sendCall($call, $retry);
    }

    private function worthResending(int $status, bool $idempotent): bool
    {
        return $status === 401 || $status === 429 || ($idempotent && $status >= 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendCall(TransportCall $call, ?RetryOptions $retry): array
    {
        return $this->sendJson(
            $call->method,
            $call->pathTemplate,
            $call->pathParams,
            $call->query,
            $call->body,
            $call->headers,
            $call->storeId,
            $retry,
            $call->idempotent,
        );
    }

    /**
     * @param array<string, scalar|null> $pathParams
     */
    private function context(string $method, string $pathTemplate, array $pathParams, ?string $storeId, bool $idempotent): RequestContext
    {
        return new RequestContext(
            $method,
            $this->basePath . PathTemplate::expand($pathTemplate, $pathParams),
            $pathTemplate,
            $storeId,
            $idempotent,
        );
    }

    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     */
    private function buildRequest(
        string $method,
        string $pathTemplate,
        array $pathParams,
        array $query,
        mixed $body,
        array $requestHeaders,
    ): RequestInterface {
        $path = PathTemplate::expand($pathTemplate, $pathParams);
        $uri = $this->buildUri($path, $query);

        $request = $this->requestFactory->createRequest($method, $uri);

        foreach ($this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request
            ->withHeader('User-Agent', 'openfga-sdk php/' . Version::VERSION)
            ->withHeader('Accept', 'application/json');

        foreach ($requestHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $authorization = $this->authorizationHeaderProvider->authorizationHeader();
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(JsonBody::encode($body)));
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response, RequestContext $context): array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            return JsonBody::decode($raw);
        } catch (\JsonException $exception) {
            throw new FgaResponseDecodeException(
                sprintf('OpenFGA API returned a non-JSON response (%s %s).', $context->method, $context->endpoint),
                $exception,
            );
        }
    }

    /**
     * @param array<string, scalar|null|list<scalar|null>> $query
     */
    private function buildUri(string $path, array $query): UriInterface
    {
        $base = rtrim($this->apiUrl, '/');
        $uri = $this->uriFactory->createUri($base . $path);
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item !== null) {
                        $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
                    }
                }

                continue;
            }
            $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        }

        if ($parts === []) {
            // withQuery('') leaves the same URI the request already has.
            /** @infection-ignore-all */
            return $uri;
        }

        return $uri->withQuery(implode('&', $parts));
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
