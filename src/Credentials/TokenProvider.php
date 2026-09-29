<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaApiAuthenticationException;
use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaTokenExchangeException;
use Curentis\OpenFga\Http\ErrorMapper;
use Curentis\OpenFga\Http\JsonBody;
use Curentis\OpenFga\Http\RetryPolicy;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Random\Randomizer;

/**
 * @internal
 */
final class TokenProvider
{
    private ?AccessToken $memoryToken = null;

    public function __construct(
        private readonly ClientCredentials|ClientAssertion $credentials,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RetryPolicy $retryPolicy,
        private readonly ClockInterface $clock,
        private readonly Randomizer $randomizer,
        private readonly ErrorMapper $errorMapper = new ErrorMapper(),
        private readonly ?CacheInterface $cache = null,
    ) {}

    public function getAccessToken(): string
    {
        $now = $this->clock->now()->getTimestamp();
        $token = $this->loadValidToken($now);
        if ($token !== null) {
            return $token->accessToken;
        }

        return $this->refreshToken($now)->accessToken;
    }

    private function refreshToken(int $now): AccessToken
    {
        $response = $this->retryPolicy->send(
            fn() => $this->httpClient->sendRequest($this->buildTokenRequest($now)),
            'POST',
            '/oauth/token',
            null,
        );

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->mapTokenError($response);
        }

        $payload = JsonBody::decode((string) $response->getBody());
        $accessToken = isset($payload['access_token']) && is_string($payload['access_token'])
            ? $payload['access_token']
            : '';
        $expiresIn = isset($payload['expires_in']) && is_int($payload['expires_in'])
            ? $payload['expires_in']
            : (isset($payload['expires_in']) && is_numeric($payload['expires_in']) ? (int) $payload['expires_in'] : 0);

        if ($accessToken === '' || $expiresIn <= 0) {
            throw new FgaTokenExchangeException(
                'Token endpoint returned an invalid token response.',
                $status,
                null,
                'missing access_token or expires_in',
                null,
                'POST',
                '/oauth/token',
                null,
                [],
                IssuerUrl::normalize($this->credentials->apiTokenIssuer),
                $this->credentials->apiAudience,
                $this->credentials->clientId,
            );
        }

        $jitter = $this->randomizer->getInt(0, 60);
        $expiresAt = $now + $expiresIn - 300 - $jitter;
        $token = new AccessToken($accessToken, $expiresAt);
        $this->memoryToken = $token;
        $this->storeCache($token, $now + $expiresIn - 300 - $jitter);

        return $token;
    }

    private function buildTokenRequest(int $now): \Psr\Http\Message\RequestInterface
    {
        $url = $this->credentials->tokenEndpoint();
        $fields = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->credentials->clientId,
            'audience' => $this->credentials->apiAudience,
        ];

        if ($this->credentials instanceof ClientCredentials) {
            $fields['client_secret'] = $this->credentials->clientSecret;
            if ($this->credentials->scopes !== null && $this->credentials->scopes !== '') {
                $fields['scope'] = $this->credentials->scopes;
            }
        } else {
            $fields['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
            $fields['client_assertion'] = ClientAssertionJwt::sign(
                $this->credentials,
                $now,
                bin2hex(random_bytes(16)),
            );
        }

        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        return $request;
    }

    private function mapTokenError(\Psr\Http\Message\ResponseInterface $response): FgaTokenExchangeException
    {
        try {
            $mapped = $this->errorMapper->map('POST', '/oauth/token', null, $response);
        } catch (FgaApiException $exception) {
            if ($exception instanceof FgaApiAuthenticationException) {
                return new FgaTokenExchangeException(
                    $exception->getMessage(),
                    $exception->statusCode,
                    $exception->apiErrorCode,
                    $exception->apiErrorMessage,
                    $exception->requestId,
                    $exception->method,
                    $exception->endpoint,
                    $exception->storeId,
                    $exception->responseHeaders,
                    IssuerUrl::normalize($this->credentials->apiTokenIssuer),
                    $this->credentials->apiAudience,
                    $this->credentials->clientId,
                    $exception,
                );
            }

            return new FgaTokenExchangeException(
                $exception->getMessage(),
                $exception->statusCode,
                $exception->apiErrorCode,
                $exception->apiErrorMessage,
                $exception->requestId,
                $exception->method,
                $exception->endpoint,
                $exception->storeId,
                $exception->responseHeaders,
                IssuerUrl::normalize($this->credentials->apiTokenIssuer),
                $this->credentials->apiAudience,
                $this->credentials->clientId,
                $exception,
            );
        }

        throw new \LogicException('Unreachable');
    }

    private function cacheKey(): string
    {
        $material = implode(
            '|',
            [
                IssuerUrl::normalize($this->credentials->apiTokenIssuer),
                $this->credentials->clientId,
                $this->credentials->apiAudience,
            ],
        );

        return 'openfga_token_' . hash('sha256', $material);
    }

    private function loadValidToken(int $now): ?AccessToken
    {
        if ($this->memoryToken !== null && !$this->memoryToken->isExpiredAt($now)) {
            return $this->memoryToken;
        }

        if ($this->cache === null) {
            return null;
        }

        $cached = $this->cache->get($this->cacheKey());
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        $parts = explode('|', $cached, 2);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            return null;
        }

        $token = new AccessToken($parts[0], (int) $parts[1]);
        if ($token->isExpiredAt($now)) {
            return null;
        }

        $this->memoryToken = $token;

        return $token;
    }

    private function storeCache(AccessToken $token, int $ttlEpoch): void
    {
        if ($this->cache === null) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $ttlSeconds = max(1, $ttlEpoch - $now);
        $this->cache->set(
            $this->cacheKey(),
            $token->accessToken . '|' . $token->expiresAtEpoch,
            $ttlSeconds,
        );
    }
}
