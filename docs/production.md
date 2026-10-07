# Production

## Timeouts

The SDK does not set an HTTP timeout. PSR-18 has no timeout parameter, so configure it on the client you inject. A `GuzzleHttp\Client` is also what enables parallel batch checks:

```php
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;

$factories = new Psr17Factory();

new ClientConfiguration(
    apiUrl: 'https://api.us1.fga.dev',
    httpClient: new Client([
        'timeout' => 2.0,
        'connect_timeout' => 1.0,
    ]),
    requestFactory: $factories,
    streamFactory: $factories,
    uriFactory: $factories,
);
```

Guzzle 7.9+ and 8 are supported. A php-http Guzzle adapter is a different class, so batch checks run sequentially with that adapter.

## Token cache

Pass a PSR-16 cache for OAuth client-credentials and client-assertion flows so PHP-FPM workers do not each request a token. The stored value is JSON:

```json
{"token":"...","expiresAt":1700000000,"refreshAt":1699999700}
```

That is plaintext unless you also set `tokenCacheKey` to 32 bytes from `sodium_crypto_secretbox_keygen()` (requires ext-sodium). The key is the only thing that makes the cache entry unreadable to someone who can read the cache. Do not put the key in the same store as the cache. API tokens are not cached; they are sent on every request. The cache key covers the issuer, client id, audience, scopes, and credential type, so two credentials never share a token.

A token has two times. `refreshAt` is `expires_in` minus `min(300s, expires_in / 10)` and up to `min(60s, expires_in / 20)` of jitter; from then on the SDK fetches a replacement. `expiresAt` is `expires_in` minus `min(30s, expires_in / 20)`; the token is never sent after that. With a shared cache, the worker that starts a refresh writes a 10-second marker key (`<cache key>.refresh`). Other workers see it and keep using the still-valid token, so a fleet of PHP-FPM workers does not refresh at once. The token request itself is retried on 429, 5xx, and network errors like any idempotent call.

`apiTokenIssuer` must be `https` except for `localhost`, `127.0.0.1`, and `::1`.

## Retries

Defaults are 3 retries, 100ms minimum wait, 10s total budget, and 5s maximum delay. The budget (`maxElapsedMs`) is a wall-clock deadline from the first attempt: time spent waiting for responses counts, not only the sleeps between them. 429 responses honor `Retry-After` plus up to 10% jitter, still capped by `maxDelayMs` and the deadline. See [error-handling.md](error-handling.md) for which calls are idempotent.

## Model id and consistency

Set `authorizationModelId` in production. `readLatestAuthorizationModel()` reads whatever the server currently calls latest, which can change under you. Expand, list objects, streamed list objects, and list users send that configured id when the request body does not already set one.

`ConsistencyPreference::HIGHER_CONSISTENCY` reads a more recent view and is slower. The default is the server's minimize-latency behavior. Use higher consistency for checks that must observe a write from a moment ago, and the default for high-volume authorization.

## Batch checks and writes

`BatchCheckOptions::$maxParallelRequests` defaults to 1 (maximum 10). Above 1, chunks run through Guzzle's pool when the injected client is a `GuzzleHttp\Client`. Each response gets the same handling as a sequential call: 2xx is decoded, 4xx is mapped to the usual exception, and a 401, 429, or retryable 5xx chunk is sent again through the normal path, which applies the retry policy and the OAuth 401 refresh. A connection failure on a chunk is re-sent the same way. Other clients run the chunks one after another.

Non-transactional writes stay sequential so `FgaPartialWriteException::$completed` is the prefix that succeeded, in order. Delete chunks are sent before write chunks, so deleting and re-writing the same tuple (for example to change its condition) leaves it written. Use a transactional write when the whole change must be atomic.
