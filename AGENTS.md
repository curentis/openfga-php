# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository.

## Golden rules

1. **When in doubt, ask the human. Do not assume.**
2. TDD: failing test → minimal code → green → commit.
3. Never hand-edit `src/Model/**` — run `composer codegen`.
4. Never weaken quality gates to make CI pass.
5. GitHub Actions: allow-list in `tools/WorkflowPolicy.php`; pin full commit SHAs with `# vX.Y.Z`.
6. Never commit secrets or `.env` files.
7. Public API = not marked `@internal`; keep backward compatible within a major version.

## Commands

| Purpose | Command |
|---|---|
| Install | `composer install` |
| Full gate | `composer check` |
| Bootstrap gate (no workflows) | `composer check:bootstrap` |
| Unit tests | `composer test:unit` |
| Integration | `docker compose -f docker-compose.test.yml up -d && composer test:integration` |
| Workflow policy | `composer workflows` |

## Skills

See `.agents/skills/*/SKILL.md`.

## PHP versions

Supported versions are listed in [SUPPORTED_RUNTIMES.md](SUPPORTED_RUNTIMES.md) and `tools/supported-php-versions.php`. When changing PHP support, update **composer.json**, **ci.yml** matrices, the doc, and run `composer workflows`.

## Local paths

- SDK root: `openfga-php/` (sibling to `OPENFGA_PHP_SDK_PLAN.md` in the Curentis workspace).
- GitHub remote: not created yet — local-only until the owner publishes `curentis/openfga-php`.
