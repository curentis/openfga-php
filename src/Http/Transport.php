<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

use Curentis\OpenFga\Client\Options\RetryOptions;
use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaResponseDecodeException;
use Curentis\OpenFga\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

final class Transport implements TransportInterface
{
    private readonly ConcurrentSenderInterface $concurrentSender;

    /**
     * @param array<string, string> $defaultHeaders
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
        ?ConcurrentSenderInterface $concurrentSender = null,
    ) {
        $this->concurrentSender = $concurrentSender ?? new SequentialConcurrentSender($httpClient);
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
        $endpoint = $this->buildRequest($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders)
            ->getUri()
            ->getPath();
        $refreshed = false;

        while (true) {
            try {
                return $this->retryPolicy->send(
                    function () use ($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders): ResponseInterface {
                        $request = $this->buildRequest($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders);

                        return $this->httpClient->sendRequest($request);
                    },
                    $method,
                    $endpoint,
                    $storeId,
                    $retry,
                    $idempotent,
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
        $endpoint = PathTemplate::expand($pathTemplate, $pathParams);

        return $this->decodeJson((string) $response->getBody(), $method, $endpoint);
    }

    /**
     * @param list<RequestInterface> $requests
     *
     * @return list<ResponseInterface>
     */
    #[\Override]
    public function sendAll(array $requests, int $maxParallel, ?string $storeId = null): array
    {
        if ($requests === []) {
            /** @infection-ignore-all */
            return [];
        }

        if ($maxParallel > 1 && $this->concurrentSender->supportsParallel()) {
            return $this->concurrentSender->send($requests, $maxParallel);
        }

        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->retryPolicy->send(
                fn(): ResponseInterface => $this->httpClient->sendRequest($request),
                $request->getMethod(),
                $request->getUri()->getPath(),
                $storeId,
            );
        }

        return $responses;
    }

    #[\Override]
    public function supportsParallel(): bool
    {
        return $this->concurrentSender->supportsParallel();
    }

    /**
     * @param array<string, scalar|null>                   $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>                        $requestHeaders
     */
    #[\Override]
    public function buildRequest(
        string $method,
        string $pathTemplate,
        array $pathParams = [],
        array $query = [],
        mixed $body = null,
        array $requestHeaders = [],
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
    private function decodeJson(string $raw, string $method, string $endpoint): array
    {
        if ($raw === '') {
            return [];
        }

        try {
            return JsonBody::decode($raw);
        } catch (\JsonException $exception) {
            throw new FgaResponseDecodeException(
                sprintf('OpenFGA API returned a non-JSON response (%s %s).', $method, $endpoint),
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
}
