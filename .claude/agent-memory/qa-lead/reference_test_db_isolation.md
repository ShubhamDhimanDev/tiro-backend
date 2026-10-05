---
name: test-db-isolation
description: backend/scripts/test-db.sh gives each test run its own ephemeral MySQL schema on the shared tiro-mysql container — use it instead of bare `php artisan test` for any full-suite run.
metadata:
  type: reference
---

`backend/scripts/test-db.sh` exists because two agents (or two runs) hitting `php artisan test`/`migrate:fresh --seed` concurrently against a single fixed `tiro_testing` schema caused real collisions (not hypothetical — happened in Phase 1). It creates a uniquely-named `tiro_testing_<epoch>_<pid>_<random>` schema per invocation via real exported `DB_*` OS env vars (not `--env`, which doesn't reliably override under Pest/PHPUnit's env-loading path — verified empirically, don't try to simplify this back), and always drops it on exit.

Usage: `scripts/test-db.sh run -- php artisan test --compact` (one-shot, auto-cleanup) or `eval "$(scripts/test-db.sh create)"` for a long-lived session. `scripts/test-db.sh cleanup-stale [hours]` sweeps orphaned schemas from crashed runs.

**Known hazard**: if `bootstrap/cache/config.php` exists (config cached via `artisan config:cache`/`optimize`), Laravel skips re-evaluating `env()` entirely and this script's DB_* overrides become invisible — the wrapped command silently runs against the real `tiro` dev DB instead. The script itself detects and refuses to proceed in that case (`check_no_config_cache`) rather than mis-isolating silently — fix is `php artisan config:clear` first.

**How to apply:** Never run a full-suite `php artisan test` bare against the shared default schema — always go through this script (or `composer test`, which already wraps it — see [[reference_composer_test_timeout]] for its own gotcha).
