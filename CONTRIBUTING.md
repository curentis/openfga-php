# Contributing to curentis/openfga-php

Thanks for helping! See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## Ground rules

- Discuss non-trivial changes in an issue first.
- Conventional Commits for PR titles (squash merge).
- Apache-2.0 inbound = outbound. No CLA/DCO.
- Follow [AGENTS.md](AGENTS.md) for agent-assisted work.

## Setup

Requirements: PHP 8.3.16+ (or current 8.4/8.5 patch; see [SUPPORTED_RUNTIMES.md](SUPPORTED_RUNTIMES.md)), Composer 2, Docker (integration tests).

```bash
cd openfga-php
composer install
composer check
docker compose -f docker-compose.test.yml up -d
composer test:integration
```

## Project map

- `src/` — SDK (`Version` today; `Client`, `Http`, `Model` in later phases).
- `tests/` — unit, contract, integration, architecture suites.
- `tools/` — codegen, workflow policy, coverage gate.
- `.github/workflows/` — CI (runs on GitHub when the repo is published).

## Making changes

- TDD; update `CHANGELOG.md` under `## [Unreleased]` for user-visible changes.
- Never edit generated `src/Model/*` by hand.
