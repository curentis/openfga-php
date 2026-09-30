# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- CI enforces **100%** PHPUnit line coverage (hand-written `src/`, excluding generated `src/Model`) and **100%** Infection MSI on PHP 8.3 with locked dependencies.
- Extension points for consumers: `OpenFgaClientFactoryInterface`, `ClientComponentFactoryInterface` (write/batch-check runners, consistency body factory), `OpenFgaApiInterface`, `TransportInterface`, and optional `clientFactory` / `componentFactory` on `ClientConfiguration`.
- User guides under [docs/](docs/README.md) and additional [examples/](examples/README.md).

### Fixed

- Codegen: deserialize explicit JSON `null` for nullable nested objects (e.g. tuple `condition`, type `metadata`) from live OpenFGA responses.

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
