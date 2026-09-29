# curentis/openfga-php

[![CI](https://github.com/curentis/openfga-php/actions/workflows/ci.yml/badge.svg)](https://github.com/curentis/openfga-php/actions/workflows/ci.yml)

PHP SDK for [OpenFGA](https://openfga.dev/) — fine-grained, relationship-based authorization.

**Status:** pre-release (`0.1.0-dev`). Implementation in progress; see the [implementation plan](../OPENFGA_PHP_SDK_PLAN.md) in the parent workspace.

## Install

```bash
composer require curentis/openfga-php
```

(Not yet published to Packagist until v1.0.)

## Requirements

- PHP **8.3**, **8.4**, and **8.5** (active php.net branches; every PR runs checks and tests on each). See [SUPPORTED_RUNTIMES.md](SUPPORTED_RUNTIMES.md).
- A PSR-18 HTTP client (e.g. Guzzle or Symfony HttpClient)
- OpenFGA server v1.10.0+ (for integration tests and production use)

## Development

```bash
composer install
composer check
docker compose -f docker-compose.test.yml up -d
composer test:integration
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md).

## Acknowledgements

- [OpenFGA](https://github.com/openfga/openfga) and the official language SDKs for API behaviour.
- [evansims/openfga-php](https://github.com/evansims/openfga-php) (archived) — design inspiration only; this is a clean-room implementation.

## License

Apache-2.0 — see [LICENSE](LICENSE).
