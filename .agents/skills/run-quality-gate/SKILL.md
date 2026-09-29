---
name: run-quality-gate
description: Run composer check and fix common failures before pushing.
---
# Run the quality gate

1. `cd openfga-php && composer check`
2. On failure: fix root cause; do not add baselines or suppressions.
3. Psalm on PHP 8.5+ is skipped locally; CI runs Psalm on PHP 8.3.
