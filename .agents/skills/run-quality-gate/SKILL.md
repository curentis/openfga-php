---
name: run-quality-gate
description: Run composer check and fix common failures before pushing.
---
# Run the quality gate

1. `cd openfga-php && composer check`
2. On failure: fix root cause; do not add baselines or suppressions without maintainer approval.
3. CI runs the same checks on PHP **8.3**, **8.4**, and **8.5** (PHPUnit, PHPStan, Psalm, and the rest).
