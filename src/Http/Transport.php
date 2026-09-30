<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Http;

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
        private readonly RetryPolicy $retryPolicy,
        private readonly AuthorizationHeaderProvider $authorizationHeaderProvider,
    ) {}

    /**
     * @param array<string, scalar|null>      $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>         $requestHeaders
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
    ): ResponseInterface {
        $request = $this->buildRequest($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders);
        $endpoint = $request->getUri()->getPath();

        return $this->retryPolicy->send(
            fn(): ResponseInterface => $this->httpClient->sendRequest($request),
            $method,
            $endpoint,
            $storeId,
        );
    }

    /**
     * @param array<string, scalar|null>      $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>         $requestHeaders
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
    ): array {
        $response = $this->send($method, $pathTemplate, $pathParams, $query, $body, $requestHeaders, $storeId);
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }

        return JsonBody::decode($raw);
    }

    /**
     * @param array<string, scalar|null>      $pathParams
     * @param array<string, scalar|null|list<scalar|null>> $query
     * @param array<string, string>         $requestHeaders
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
     * @param array<string, scalar|null|list<scalar|null>> $query
     */
    private function buildUri(string $path, array $query): UriInterface
    {
        $base = rtrim($this->apiUrl, '/');
        $uri = $this->uriFactory->createUri($base . $path);

        if ($query === []) {
            return $uri;
        }

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
            return $uri;
        }

        return $uri->withQuery(implode('&', $parts));
    }
}
