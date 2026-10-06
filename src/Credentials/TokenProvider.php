<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaTokenExchangeException;
use Curentis\OpenFga\Http\JsonBody;
use Curentis\OpenFga\Http\RequestContext;
use Curentis\OpenFga\Http\RetryPolicyInterface;
use Curentis\OpenFga\Observability\SdkTelemetry;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\SimpleCache\CacheInterface;
use Random\Randomizer;

/**
 * Fetches and caches OAuth access tokens.
 *
 * A token is replaced from its refresh point, which sits a proportional buffer before the server expiry.
 * With a shared cache, the worker that starts a refresh sets a short-lived marker; other workers keep
 * using the still-valid token until the marker clears instead of all refreshing at once.
 *
 * @internal
 */
final class TokenProvider
{
    private const int REFRESH_MARKER_SECONDS = 10;

    private ?AccessToken $memoryToken = null;

    public function __construct(
        private readonly ClientCredentials|ClientAssertion $credentials,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RetryPolicyInterface $retryPolicy,
        private readonly ClockInterface $clock,
        private readonly Randomizer $randomizer,
        private readonly ?CacheInterface $cache = null,
        private readonly TokenCacheCodec $cacheCodec = new JsonTokenCacheCodec(),
        private readonly SdkTelemetry $telemetry = new SdkTelemetry(),
    ) {}

    public function invalidate(): void
    {
        $this->memoryToken = null;
        if ($this->cache !== null) {
            $this->cache->delete($this->cacheKey());
        }
    }

    public function getAccessToken(): string
    {
        $now = $this->clock->now()->getTimestamp();
        $token = $this->currentToken($now);
        if ($token !== null && (!$token->needsRefreshAt($now) || $this->refreshInProgress())) {
            return $token->accessToken;
        }

        return $this->refreshToken($now)->accessToken;
    }

    private function currentToken(int $now): ?AccessToken
    {
        $memory = $this->memoryToken;
        if ($memory !== null && !$memory->needsRefreshAt($now)) {
            return $memory;
        }

        $cached = $this->cachedToken($now);
        if ($cached !== null) {
            $this->memoryToken = $cached;

            return $cached;
        }

        return $memory !== null && !$memory->isExpiredAt($now) ? $memory : null;
    }

    private function cachedToken(int $now): ?AccessToken
    {
        if ($this->cache === null) {
            return null;
        }

        $cached = $this->cache->get($this->cacheKey());
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        $token = $this->cacheCodec->decode($cached);
        if ($token === null || $token->isExpiredAt($now)) {
            return null;
        }

        return $token;
    }

    private function refreshInProgress(): bool
    {
        return $this->cache !== null && $this->cache->has($this->refreshMarkerKey());
    }

    private function refreshToken(int $now): AccessToken
    {
        if ($this->cache === null) {
            return $this->fetchToken($now);
        }

        $this->cache->set($this->refreshMarkerKey(), '1', self::REFRESH_MARKER_SECONDS);
        try {
            return $this->fetchToken($now);
        } finally {
            $this->cache->delete($this->refreshMarkerKey());
        }
    }

    private function fetchToken(int $now): AccessToken
    {
        $endpoint = $this->credentials->tokenEndpoint();
        $path = parse_url($endpoint, PHP_URL_PATH);
        // IssuerUrl::tokenEndpoint() always returns a non-empty path.
        /** @infection-ignore-all */
        $endpointPath = is_string($path) && $path !== '' ? $path : '/oauth/token';
        try {
            // Each attempt builds a new request, and a new assertion `jti`, so retrying is safe.
            $response = $this->retryPolicy->send(
                fn() => $this->httpClient->sendRequest($this->buildTokenRequest($now)),
                new RequestContext('POST', $endpointPath),
            );
        } catch (FgaApiException|FgaNetworkException $exception) {
            throw $this->tokenExchangeException($exception, $endpointPath);
        }

        $status = $response->getStatusCode();
        try {
            $payload = JsonBody::decode((string) $response->getBody());
        } catch (\JsonException $exception) {
            throw $this->invalidResponse('Token endpoint returned a non-JSON response.', $status, 'non-JSON token response', $endpointPath, $exception);
        }
        $accessToken = isset($payload['access_token']) && is_string($payload['access_token'])
            ? $payload['access_token']
            : '';
        $expiresIn = self::expiresInSeconds($payload['expires_in'] ?? null);

        if ($accessToken === '' || $expiresIn < 1) {
            throw $this->invalidResponse(
                sprintf('Token endpoint returned an invalid token response (expires_in=%d).', $expiresIn),
                $status,
                'missing access_token or expires_in',
                $endpointPath,
            );
        }

        $token = $this->tokenFor($accessToken, $now, $expiresIn);
        $this->telemetry->tokenRefreshed($this->credentials->clientId);
        $this->memoryToken = $token;
        $this->storeCache($token, $now);

        return $token;
    }

    private function buildTokenRequest(int $now): RequestInterface
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
                self::assertionId(),
            );
        }

        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        return $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }

    private static function expiresInSeconds(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function assertionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function cacheKey(): string
    {
        $credentials = $this->credentials;
        $material = implode(
            '|',
            [
                $credentials instanceof ClientCredentials ? 'client_secret' : 'client_assertion',
                IssuerUrl::normalize($credentials->apiTokenIssuer),
                $credentials->clientId,
                $credentials->apiAudience,
                $credentials instanceof ClientCredentials ? ($credentials->scopes ?? '') : '',
            ],
        );

        return 'openfga_token_' . hash('sha256', $material);
    }

    private function refreshMarkerKey(): string
    {
        return $this->cacheKey() . '.refresh';
    }

    private function storeCache(AccessToken $token, int $now): void
    {
        if ($this->cache === null) {
            return;
        }

        // Expiry is at least one second ahead, so max(0, …) matches max(1, …).
        /** @infection-ignore-all */
        $ttlSeconds = max(1, $token->expiresAtEpoch - $now);
        $this->cache->set($this->cacheKey(), $this->cacheCodec->encode($token), $ttlSeconds);
    }

    private function tokenFor(string $accessToken, int $now, int $expiresIn): AccessToken
    {
        $buffer = min(300, intdiv($expiresIn, 10));
        $jitterMax = min(60, intdiv($expiresIn, 20));
        $jitter = $jitterMax > 0 ? $this->randomizer->getInt(0, $jitterMax) : 0;
        $margin = min(30, intdiv($expiresIn, 20));

        // The refresh buffer is never smaller than the expiry margin, so refreshAt <= expiresAt.
        return new AccessToken(
            $accessToken,
            $now + ($expiresIn - $margin),
            $now + ($expiresIn - $buffer - $jitter),
        );
    }

    private function invalidResponse(
        string $message,
        int $status,
        string $description,
        string $endpointPath,
        ?\Throwable $previous = null,
    ): FgaTokenExchangeException {
        return new FgaTokenExchangeException(
            $message,
            $status,
            null,
            $description,
            null,
            'POST',
            $endpointPath,
            null,
            [],
            IssuerUrl::normalize($this->credentials->apiTokenIssuer),
            $this->credentials->apiAudience,
            $this->credentials->clientId,
            $previous,
        );
    }

    private function tokenExchangeException(\Throwable $exception, string $endpointPath): FgaTokenExchangeException
    {
        $status = $exception instanceof FgaApiException ? $exception->statusCode : 0;
        $body = $exception instanceof FgaApiException ? $exception->responseBody : '';
        [$error, $description] = $this->oauthError($body !== '' ? $body : $exception->getMessage());
        $message = $description !== ''
            ? sprintf('Token endpoint rejected the client (%s).', $description)
            : sprintf('Token endpoint request failed (%s).', $error !== '' ? $error : $exception->getMessage());

        return new FgaTokenExchangeException(
            $message,
            $status,
            $error !== '' ? $error : null,
            $description !== '' ? $description : $exception->getMessage(),
            $exception instanceof FgaApiException ? $exception->requestId : null,
            'POST',
            $endpointPath,
            null,
            $exception instanceof FgaApiException ? $exception->responseHeaders : [],
            IssuerUrl::normalize($this->credentials->apiTokenIssuer),
            $this->credentials->apiAudience,
            $this->credentials->clientId,
            $exception,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function oauthError(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['', ''];
        }
        if (!is_array($decoded)) {
            // Offset reads on a non-array still produce an empty error pair.
            /** @infection-ignore-all */
            return ['', ''];
        }
        $error = isset($decoded['error']) && is_string($decoded['error']) ? $decoded['error'] : '';
        $description = isset($decoded['error_description']) && is_string($decoded['error_description'])
            ? $decoded['error_description']
            : '';

        return [$error, $description];
    }
}
