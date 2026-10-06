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

A php-http Guzzle adapter is a different class. Batch checks stay sequential with that adapter, which keeps retries and the 401 refresh on each chunk.

## Token cache

Pass a PSR-16 cache for OAuth client-credentials and client-assertion flows so PHP-FPM workers do not each request a token. The stored value is JSON:

```json
{"token":"...","expiresAt":1700000000}
```

That is plaintext unless you also set `tokenCacheKey` to 32 bytes from `sodium_crypto_secretbox_keygen()` (requires ext-sodium). The key is the only thing that makes the cache entry unreadable to someone who can read the cache. Do not put the key in the same store as the cache. API tokens are not cached; they are sent on every request.

Token lifetime keeps a proportional safety buffer (`min(300s, expires_in / 10)` plus a small jitter). A token with `expires_in` of a few minutes is still used until that buffer, not discarded immediately.

`apiTokenIssuer` must be `https` except for `localhost`, `127.0.0.1`, and `::1`.

## Retries

Defaults are 3 retries, 100ms minimum wait, 10s total budget, and 5s maximum delay. 429 responses honor `Retry-After` plus up to 10% jitter, still capped by `maxDelayMs` and the elapsed budget. See [error-handling.md](error-handling.md) for which calls are idempotent.

## Model id and consistency

Set `authorizationModelId` in production. `readLatestAuthorizationModel()` reads whatever the server currently calls latest, which can change under you. Expand, list objects, streamed list objects, and list users send that configured id when the request body does not already set one.

`ConsistencyPreference::HIGHER_CONSISTENCY` reads a more recent view and is slower. The default is the server's minimize-latency behavior. Use higher consistency for checks that must observe a write from a moment ago, and the default for high-volume authorization.

## Batch checks and writes

`BatchCheckOptions::$maxParallelRequests` defaults to 1 (maximum 10). Above 1, chunks run through Guzzle's pool only when the injected client is a `GuzzleHttp\Client`. That path does not apply the retry policy or the 401 refresh to each chunk. Leave the limit at 1 when those matter.

Non-transactional writes stay sequential so `FgaPartialWriteException::$completed` is the prefix that succeeded, in order.
