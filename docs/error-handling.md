# Error handling

Every failure raised by this SDK implements `Curentis\OpenFga\Exception\FgaException`. Catch that when you want one handler, or catch a subclass when the status should change what you do.

## Hierarchy

| Exception | When |
|-----------|------|
| `FgaValidationException` | The SDK rejected the call before HTTP: empty write, bad ULID, invalid correlation ID, bad issuer URL. `FgaRequiredParamException` is the missing store or model id case. |
| `FgaNetworkException` | The PSR-18 client threw. `getPrevious()` is the client exception. Idempotent calls are retried; other calls are not. |
| `FgaResponseDecodeException` | The HTTP status was success but the body was not the JSON the operation expects, including a bad NDJSON line. |
| `FgaApiValidationException` | HTTP 400 or 422. |
| `FgaApiAuthenticationException` | HTTP 401 or 403. `FgaTokenExchangeException` is the OAuth token-endpoint variant and carries `issuer`, `audience`, and `clientId`. |
| `FgaApiNotFoundException` | HTTP 404. |
| `FgaApiRateLimitException` | HTTP 429. `retryAfterMs` is set when the server sent `Retry-After` or a rate-limit reset header. |
| `FgaApiException` | Other 4xx. |
| `FgaApiInternalException` | 5xx, including 501 (which is not retried). |
| `FgaPartialWriteException` | A non-transactional write stopped on something other than 400/404/422. `completed` holds the tuple results that already succeeded. `getPrevious()` is the request that stopped the run. |

`FgaApiException` (and the subclasses above it) expose `statusCode`, `apiErrorCode`, `apiErrorMessage`, `requestId`, `method`, `endpoint`, `storeId`, `responseHeaders`, and `responseBody`. The message includes a truncated, control-character-stripped excerpt. The full body is only on `responseBody`.

`requestId` is the first non-empty `x-request-id` or `fga-query-id` response header.

## What to catch

```php
use Curentis\OpenFga\Exception\FgaApiRateLimitException;
use Curentis\OpenFga\Exception\FgaException;
use Curentis\OpenFga\Exception\FgaPartialWriteException;

try {
    $fga->write($request, $options);
} catch (FgaPartialWriteException $e) {
    // $e->completed succeeded. $e->getPrevious() is why the run stopped.
} catch (FgaApiRateLimitException $e) {
    // Wait $e->retryAfterMs before calling again, or let the SDK retry.
} catch (FgaException $e) {
    // Safe to log $e->getMessage(). Do not log response headers.
}
```

## Retries

`RetryOptions` controls `maxRetry` (0–15), `minWaitMs`, `maxElapsedMs` (default 10s), and `maxDelayMs` (default 5s). The SDK retries 429s and, for idempotent calls, network errors and 500–599 except 501. `maxElapsedMs` is measured from the first attempt and includes request time; a retry is skipped when the next sleep would end past that deadline.

`write` is idempotent only when there is nothing to write or `OnDuplicateWrites::Ignore` is set, and there is nothing to delete or `OnMissingDeletes::Ignore` is set. Create store, write authorization model, and write assertions are not idempotent: they retry 429 only. The OAuth token request builds a fresh request (and a fresh assertion `jti`) on every attempt, so it is retried like an idempotent call.

`executeApiRequest()` and `executeStreamedApiRequest()` treat GET, HEAD, OPTIONS, and POST to `check`, `batch-check`, `expand`, `list-objects`, `streamed-list-objects`, `list-users`, and `read` as idempotent. Pass `idempotent: true` or `false` to override.

Set `RequestOptions::$retry` to override the client default for one call.

On HTTP 401 from an OAuth credential, the SDK invalidates the cached token once, rebuilds the request, and tries again. An API token cannot be refreshed; that 401 is thrown.

## Batch check

Each item is a `ClientBatchCheckItemResult` with `correlationId`, the original `check`, and `result` (`BatchCheckSingleResult`). If the server omits a correlation id, `result->allowed` is `false` and `result->error->message` explains the gap. Do not treat a missing entry as an implicit allow. Match results by `correlationId` or `check`: a custom `BatchCheckRunnerInterface` does not have to keep input order.

With `maxParallelRequests` above 1, a chunk that fails is mapped to the same exception a sequential call would raise. The first failing chunk is thrown and the other results are discarded.

## Non-transactional writes

With `TransactionOptions(disable: true)`, tuples are written in chunks. 400, 422, and 404 become per-tuple failures and the run continues. Any other failure throws `FgaPartialWriteException` and later chunks are not sent. Delete chunks go first, then write chunks, and `tupleResults` lists them in that order.

## Telemetry listeners

PSR-14 listeners and PSR-3 loggers passed to `SdkTelemetry` run inline with the request. An exception from either is caught; a failing listener is reported as an `openfga.telemetry_failed` warning on the logger, and the SDK call continues. Keep listeners fast: they add to request latency.
