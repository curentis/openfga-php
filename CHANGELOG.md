# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-12

Initial release of the PHP SDK for [OpenFGA](https://openfga.dev). Requires PHP 8.3+ and OpenFGA v1.10.0+.

### Added

- `OpenFgaClient` covering every OpenFGA HTTP API operation: stores, authorization models, tuples and changes, assertions, check, batch check, expand, list objects (including streamed), and list users.
- Client helpers: `batchCheck` with correlation IDs, `listRelations`, non-transactional `write` in chunks (deletes sent before writes), and `readLatestAuthorizationModel`.
- `executeApiRequest()` and `executeStreamedApiRequest()` for calling endpoints directly. Idempotency is inferred from the method and path and can be overridden.
- Works with any PSR-18 HTTP client and PSR-17 factories, discovered automatically through `php-http/discovery`. With a `GuzzleHttp\Client` (Guzzle 7.9+ or 8), `batchCheck` runs in parallel up to `maxParallelRequests`.
- Retries with jittered exponential backoff: 429 on every call (honouring `Retry-After`), and retryable 5xx and network errors on idempotent calls. Configure the retry count, per-attempt delay cap (`maxDelayMs`), and a wall-clock deadline (`maxElapsedMs`); `RequestOptions::$retry` overrides them per call. Writes retry server errors only when duplicate writes and missing deletes are both ignored.
- Authentication with an API token, or OAuth2 client credentials (`ClientCredentials`) and private-key JWT client assertion (`ClientAssertion`). OAuth tokens refresh ahead of expiry with jitter, are refreshed once after an HTTP 401, and can be shared across workers through a PSR-16 cache. The cache entry can be encrypted with sodium (`tokenCacheKey`).
- Typed exception hierarchy under `FgaException`: validation, authentication, not-found, rate-limit, internal, network, response-decode, token-exchange, and partial-write errors. Only `FgaException` subclasses are thrown, whatever the HTTP client or server returns.
- Optional PSR-3 logging and PSR-14 events (`RequestFinished`, `RetryScheduled`, `TokenRefreshed`). `RequestFinished` carries the route template, duration, and a `RequestOutcome`. A failing logger or listener never breaks the request.
- Extension points: `OpenFgaClientFactoryInterface`, `ClientComponentFactoryInterface` (write and batch-check runners, consistency body factory), `OpenFgaApiInterface`, `TransportInterface`, `ParallelTransportInterface`, and `RetryPolicyInterface` with `RequestContext`.
- Typed `Model\*` classes generated from the vendored OpenFGA OpenAPI spec. Unknown enum values from newer servers are kept as strings.
- User guides under [docs/](docs/README.md) and runnable [examples/](examples/README.md).

[Unreleased]: https://github.com/curentis/openfga-php/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/curentis/openfga-php/releases/tag/v1.0.0
