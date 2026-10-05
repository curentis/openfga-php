# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- CI enforces **100%** PHPUnit line coverage (hand-written `src/`, excluding generated `src/Model`) and **100%** Infection MSI on PHP 8.3 with locked dependencies.
- Extension points for consumers: `OpenFgaClientFactoryInterface`, `ClientComponentFactoryInterface` (write/batch-check runners, consistency body factory), `OpenFgaApiInterface`, `TransportInterface`, `RetryPolicyInterface`, and optional `componentFactory` on `ClientConfiguration`.
- User guides under [docs/](docs/README.md) and additional [examples/](examples/README.md).
- `FgaResponseDecodeException` for malformed API payloads and `FgaPartialWriteException` when a non-transactional write stops after a non-validation failure.
- Retry elapsed budget (`maxElapsedMs`) and per-attempt delay cap (`maxDelayMs`). Per-call `RequestOptions::$retry` is applied.
- One OAuth token refresh after HTTP 401, then the request is rebuilt.
- Optional PSR-3 / PSR-14 telemetry (`RequestFinished`, `RetryScheduled`, `TokenRefreshed`).
- Optional sodium encryption for the OAuth token cache (`tokenCacheKey`).
- Parallel `batchCheck` when `maxParallelRequests` is greater than 1 and the PSR-18 client is a `GuzzleHttp\Client`.
- `composer audit` in CI, Dependabot, and a tag-triggered release workflow.

### Changed

- Exception types are public API. Catch `FgaException` or a specific subclass.
- `OnDuplicateWrites` and `OnMissingDeletes` are backed enums. `ConflictOptions` omits a field when the enum is null.
- Batch-check item results expose `correlationId`, `check`, and `result`. A missing server result is `allowed: false` with a `CheckError`.
- `listRelations` de-duplicates relations and lets the batch runner assign correlation IDs.
- Expand, list objects, streamed list objects, and list users receive the resolved authorization model ID.
- OAuth token expiry uses a proportional buffer, so short-lived tokens stay usable.
- Cached OAuth tokens are stored as JSON `{"token","expiresAt"}`.
- Writes retry server errors only when duplicate writes and missing deletes are both `ignore` (or that side of the request is empty). `write` defaults to non-idempotent.
- `readLatestAuthorizationModel` uses the first model from the list response.
- Response enums keep unknown values as strings instead of rejecting the payload.

### Removed

- `ClientConfiguration::$clientFactory`, `$timeoutSeconds`, and `DEFAULT_MAX_PARALLEL_REQUESTS`. Set timeouts on the PSR-18 client. Choose a client factory by calling it.

### Fixed

- Codegen: deserialize explicit JSON `null` for nullable nested objects (e.g. tuple `condition`, type `metadata`) from live OpenFGA responses.
- HTTP client exceptions, invalid JSON, and token-endpoint failures are raised as `FgaException` subclasses.
- Non-JSON error bodies are stripped of control characters and truncated in the exception message. The full body remains on `FgaApiException::$responseBody`.

## [1.0.0] - 2026-09-30

### Added

- Public `OpenFgaClient` with all OpenFGA HTTP API operations and ergonomic helpers (`batchCheck`, `listRelations`, non-transactional `write`, `readLatestAuthorizationModel`, streamed list objects).
- PSR-18 transport with retries, error mapping, API token and OAuth credentials (`ClientCredentials`, `ClientAssertion`).
- Generated `Model\*` types from the vendored OpenAPI spec; `composer codegen` workflow.
- Unit, contract, architecture, and integration test suites; README quickstart and `examples/quickstart.php`.

## [0.1.0-dev] - 2026-09-29

### Added

- [SUPPORTED_RUNTIMES.md](SUPPORTED_RUNTIMES.md) (php.net active branches 8.3–8.5; CI on every PR).
- Repository bootstrap: tooling, tests, workflow policy checker, CI workflow (local development).
- Vendored OpenFGA OpenAPI spec (`spec/openapi.json`, `spec/SOURCE`).
