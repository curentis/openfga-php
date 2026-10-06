<?php

declare(strict_types=1);

namespace Curentis\OpenFga\Credentials;

use Curentis\OpenFga\Exception\FgaApiException;
use Curentis\OpenFga\Exception\FgaNetworkException;
use Curentis\OpenFga\Exception\FgaTokenExchangeException;
use Curentis\OpenFga\Http\JsonBody;
use Curentis\OpenFga\Http\RetryPolicy;
use Curentis\OpenFga\Observability\SdkTelemetry;
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
        $token = $this->loadValidToken($now);
        if ($token !== null) {
            return $token->accessToken;
        }

        return $this->refreshToken($now)->accessToken;
    }

    private function refreshToken(int $now): AccessToken
    {
        $endpoint = $this->credentials->tokenEndpoint();
        $path = parse_url($endpoint, PHP_URL_PATH);
        // IssuerUrl::tokenEndpoint() always returns a non-empty path.
        /** @infection-ignore-all */
        $endpointPath = is_string($path) && $path !== '' ? $path : '/oauth/token';
        try {
            $response = $this->retryPolicy->send(
                fn() => $this->httpClient->sendRequest($this->buildTokenRequest($now)),
                'POST',
                $endpointPath,
                null,
                null,
                false,
            );
        } catch (FgaApiException|FgaNetworkException $exception) {
            throw $this->tokenExchangeException($exception, $endpointPath);
        }

        $status = $response->getStatusCode();
        try {
            $payload = JsonBody::decode((string) $response->getBody());
        } catch (\JsonException $exception) {
            throw $this->tokenExchangeException(
                new FgaTokenExchangeException(
                    'Token endpoint returned a non-JSON response.',
                    $status,
                    null,
                    'non-JSON token response',
                    null,
                    'POST',
                    $endpointPath,
                    null,
                    [],
                    IssuerUrl::normalize($this->credentials->apiTokenIssuer),
                    $this->credentials->apiAudience,
                    $this->credentials->clientId,
                    $exception,
                ),
                $endpointPath,
            );
        }
        $accessToken = isset($payload['access_token']) && is_string($payload['access_token'])
            ? $payload['access_token']
            : '';
        $expiresIn = self::expiresInSeconds($payload['expires_in'] ?? null);

        if ($accessToken === '' || $expiresIn < 1) {
            throw new FgaTokenExchangeException(
                sprintf('Token endpoint returned an invalid token response (expires_in=%d).', $expiresIn),
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

        $expiresAt = $this->expiryEpoch($now, $expiresIn);
        $token = new AccessToken($accessToken, $expiresAt);
        $this->telemetry->tokenRefreshed($this->credentials->clientId);
        $this->memoryToken = $token;
        $this->storeCache($token, $expiresAt);

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
                self::assertionId(),
            );
        }

        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        return $request;
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

        $decoded = $this->cacheCodec->decode($cached);
        if ($decoded === null) {
            return null;
        }

        $token = new AccessToken($decoded[0], $decoded[1]);
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
        // Expiry is at least one second ahead, so max(0, …) matches max(1, …).
        /** @infection-ignore-all */
        $ttlSeconds = max(1, $ttlEpoch - $now);
        $this->cache->set(
            $this->cacheKey(),
            $this->cacheCodec->encode($token->accessToken, $token->expiresAtEpoch),
            $ttlSeconds,
        );
    }

    private function expiryEpoch(int $now, int $expiresIn): int
    {
        $buffer = min(300, intdiv($expiresIn, 10));
        $jitterMax = min(60, intdiv($expiresIn, 20));
        $jitter = $jitterMax > 0 ? $this->randomizer->getInt(0, $jitterMax) : 0;

        // expiresIn is at least 1 and the buffer plus jitter stay below it.
        return $now + ($expiresIn - $buffer - $jitter);
    }

    private function tokenExchangeException(\Throwable $exception, string $endpointPath): FgaTokenExchangeException
    {
        if ($exception instanceof FgaTokenExchangeException) {
            return $exception;
        }

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
