#!/usr/bin/env bash
# Per-run ephemeral MySQL test database for concurrent agent test runs.
#
# WHY THIS EXISTS (2026-09-11): backend/phpunit.xml used to hardcode a single
# fixed DB_DATABASE=tiro_testing for every test run on the shared tiro-mysql
# container. Two agents (or two runs) hitting `php artisan test` /
# `migrate:fresh --seed` at the same time were silently sharing that one
# schema — real collision, not hypothetical (backend-tester and
# frontend-tester both hit variants of this in the same round of testing).
# The fix is per-invocation isolation: this script creates a uniquely-named
# `tiro_testing_<suffix>` schema on the SAME shared tiro-mysql server (a
# whole new MySQL container per test run was considered and rejected — this
# dev machine already runs many unrelated Docker projects concurrently, and
# a fresh mysql:8 container takes ~15s to initialize; a new schema on an
# already-running server takes <1s), points the invocation at it, and drops
# it afterward.
#
# HOW ISOLATION ACTUALLY WORKS (verified empirically 2026-09-11, not
# assumed): Laravel's `--env=X` flag does NOT reliably override DB_* under
# PHPUnit/Pest's runningUnitTests() env-loading path (it already auto-loads
# backend/.env.testing before `--env` gets a chance to redirect it, and
# dotenv won't overwrite what it already loaded from a file with a
# *different* file — tested and confirmed this does not take effect).
# What DOES work, confirmed by direct testing: exporting DB_DATABASE (etc.)
# as real OS-level process environment variables before invoking php/pest/
# artisan. Laravel's dotenv (`createImmutable`) never overwrites a variable
# that's already present in the actual process environment, regardless of
# which .env file would otherwise set it — so a real exported env var always
# wins over backend/.env.testing's file value. That's the mechanism this
# script relies on; don't "simplify" this back to a --env flag without
# re-verifying, it was tried and it does not work for Pest/PHPUnit runs.
#
# USAGE
#   scripts/test-db.sh run -- php artisan test --compact
#   scripts/test-db.sh run -- vendor/bin/pest
#   scripts/test-db.sh run -- php artisan migrate:fresh --seed
#     One-shot: creates an isolated schema, runs the given command with
#     DB_DATABASE (and the rest of the DB_* vars, for safety) pointed at it,
#     then always drops the schema on exit — success, failure, or Ctrl-C.
#
#   eval "$(scripts/test-db.sh create)"
#     For a long-lived session (e.g. start `php artisan serve` / `composer
#     run dev` against an isolated DB, then drive Playwright against it):
#     prints `export DB_...=...` lines to stdout — eval them into your
#     current shell, then everything you run in that shell (server, artisan
#     commands, etc.) targets the isolated schema until you exit the shell
#     or explicitly drop it:
#   scripts/test-db.sh drop "$TIRO_TEST_DB_SUFFIX"
#     Drops a specific schema created by `create` (the suffix is exported as
#     TIRO_TEST_DB_SUFFIX by `create`, alongside the DB_* vars).
#
#   scripts/test-db.sh cleanup-stale [max-age-hours, default 4]
#     Drops any leftover `tiro_testing_*` schema (never the bare
#     `tiro_testing` itself) older than the given age — a session that
#     crashed before its own `drop`/trap ran leaves one of these behind;
#     run this periodically or before a big test push to sweep them up.
#
# Never touches the `tiro` database (real dev data) or the plain
# `tiro_testing` schema (the shared default for solo `--filter=testName`
# loops during active development — collision risk there is low, it's one
# agent iterating on its own work, and wrapping every narrow filter run in a
# create/drop cycle would slow down the tight edit-test loop for no benefit).
#
# KNOWN HAZARD -- config caching bypasses this entirely (found + fixed
# 2026-09-14/15): the isolation mechanism above relies on Laravel's
# LoadConfiguration bootstrapper calling `env('DB_DATABASE', ...)` at boot
# time. If `bootstrap/cache/config.php` exists (i.e. `php artisan
# config:cache` or `optimize` was run and never cleared), Laravel skips
# reading config/*.php -- and therefore skips every env() call in it --
# entirely, for every command, test or not (confirmed by reading
# vendor/laravel/framework/.../Bootstrap/LoadConfiguration.php: it does
# `if (file_exists($cached)) { $items = require $cached; ... } else {
# loadConfigurationFiles(...) }` -- there is no fallback merge, the cached
# array is used as-is). This script's exported DB_* vars have no path left
# to reach config() when that happens, so the wrapped command silently runs
# against whatever DB was live when the cache was built (in practice, the
# real `tiro` dev DB) with no error at all -- confirmed by deliberately
# reproducing it: cached config against the real .env, then `run --
# php artisan migrate:status` reported the real DB's full migration history
# instead of "Migration table not found" on the fresh empty isolated schema.
# `create` and `run` both refuse to proceed (see check_no_config_cache
# below) if they find that file, rather than silently mis-isolating --
# `php artisan config:clear` first, then retry.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_TESTING_FILE="$BACKEND_DIR/.env.testing"
CACHED_CONFIG_PATH="$BACKEND_DIR/bootstrap/cache/config.php"
MYSQL_CONTAINER="tiro-mysql"

check_no_config_cache() {
  # See "KNOWN HAZARD" above. A cached config makes every DB_* override this
  # script sets completely invisible to Laravel, for any command -- fail
  # loudly here instead of letting the caller silently land on the real DB.
  if [ -f "$CACHED_CONFIG_PATH" ]; then
    echo "[test-db] ERROR: ${CACHED_CONFIG_PATH} exists (Laravel config is cached)." >&2
    echo "[test-db] While config is cached, Laravel never re-evaluates env('DB_DATABASE', ...)," >&2
    echo "[test-db] so this script's isolated-schema override would be silently ignored and the" >&2
    echo "[test-db] wrapped command would run against whichever DB was live when the cache was" >&2
    echo "[test-db] built (almost always the real 'tiro' dev database)." >&2
    echo "[test-db] Fix: run 'php artisan config:clear' in ${BACKEND_DIR}, then retry." >&2
    exit 1
  fi
}

if [ ! -f "$ENV_TESTING_FILE" ]; then
  echo "test-db.sh: $ENV_TESTING_FILE not found — cannot derive base DB_* values." >&2
  exit 1
fi

read_env_val() {
  # Reads KEY=value out of .env.testing (last match wins, ignores comments).
  grep -E "^$1=" "$ENV_TESTING_FILE" | tail -n1 | cut -d= -f2-
}

DB_HOST_VAL="$(read_env_val DB_HOST)"
DB_PORT_VAL="$(read_env_val DB_PORT)"
DB_USER_VAL="$(read_env_val DB_USERNAME)"
DB_PASS_VAL="$(read_env_val DB_PASSWORD)"

mysql_exec() {
  docker exec "$MYSQL_CONTAINER" mysql -u"$DB_USER_VAL" -p"$DB_PASS_VAL" -N -B -e "$1"
}

new_suffix() {
  # PID + epoch seconds + bash $RANDOM — unique enough for concurrent local
  # agent runs on one host, which is all this needs to cover.
  echo "$(date +%s)_$$_${RANDOM:-0}"
}

cmd="${1:-}"
shift || true

case "$cmd" in
  create)
    check_no_config_cache
    SUFFIX="$(new_suffix)"
    DB_NAME="tiro_testing_${SUFFIX}"
    echo "[test-db] creating ${DB_NAME} on ${MYSQL_CONTAINER}" >&2
    mysql_exec "CREATE DATABASE \`${DB_NAME}\`;"
    printf 'export DB_CONNECTION=mysql\n'
    printf 'export DB_HOST=%s\n' "$DB_HOST_VAL"
    printf 'export DB_PORT=%s\n' "$DB_PORT_VAL"
    printf 'export DB_DATABASE=%s\n' "$DB_NAME"
    printf 'export DB_USERNAME=%s\n' "$DB_USER_VAL"
    printf 'export DB_PASSWORD=%s\n' "$DB_PASS_VAL"
    printf 'export TIRO_TEST_DB_SUFFIX=%s\n' "$SUFFIX"
    ;;

  drop)
    SUFFIX="${1:-${TIRO_TEST_DB_SUFFIX:-}}"
    if [ -z "$SUFFIX" ]; then
      echo "Usage: $0 drop <suffix>  (or set TIRO_TEST_DB_SUFFIX)" >&2
      exit 1
    fi
    DB_NAME="tiro_testing_${SUFFIX}"
    echo "[test-db] dropping ${DB_NAME}" >&2
    mysql_exec "DROP DATABASE IF EXISTS \`${DB_NAME}\`;"
    ;;

  run)
    if [ "${1:-}" = "--" ]; then shift; fi
    if [ "$#" -eq 0 ]; then
      echo "Usage: $0 run -- <command to run, e.g. php artisan test>" >&2
      exit 1
    fi
    check_no_config_cache
    SUFFIX="$(new_suffix)"
    DB_NAME="tiro_testing_${SUFFIX}"

    cleanup() {
      echo "[test-db] dropping ${DB_NAME}" >&2
      mysql_exec "DROP DATABASE IF EXISTS \`${DB_NAME}\`;" \
        || echo "[test-db] warning: could not drop ${DB_NAME} (container down?) — clean up manually: docker exec ${MYSQL_CONTAINER} mysql -u${DB_USER_VAL} -p*** -e \"DROP DATABASE IF EXISTS \\\`${DB_NAME}\\\`;\"" >&2
    }
    trap cleanup EXIT

    echo "[test-db] creating ${DB_NAME} on ${MYSQL_CONTAINER}" >&2
    mysql_exec "CREATE DATABASE \`${DB_NAME}\`;"

    echo "[test-db] running against ${DB_NAME}: $*" >&2
    cd "$BACKEND_DIR"
    DB_CONNECTION=mysql \
    DB_HOST="$DB_HOST_VAL" \
    DB_PORT="$DB_PORT_VAL" \
    DB_DATABASE="$DB_NAME" \
    DB_USERNAME="$DB_USER_VAL" \
    DB_PASSWORD="$DB_PASS_VAL" \
      "$@"
    ;;

  cleanup-stale)
    # information_schema.SCHEMATA has no CREATE_TIME column in MySQL (that
    # only exists on information_schema.TABLES) -- and joining against
    # TABLES would miss any schema that was CREATE DATABASE'd but crashed
    # before migrate ever ran, since a schema with zero tables has no TABLES
    # row to join on. Instead, parse the creation timestamp already embedded
    # in the schema name by new_suffix() (`<epoch>_<pid>_<random>`), so this
    # doesn't depend on MySQL tracking creation time at all.
    MAX_AGE_HOURS="${1:-4}"
    echo "[test-db] sweeping tiro_testing_* schemas older than ${MAX_AGE_HOURS}h" >&2
    MAX_AGE_SECONDS=$((MAX_AGE_HOURS * 3600))
    NOW_EPOCH="$(date +%s)"
    ALL_SCHEMAS="$(mysql_exec "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'tiro_testing\\_%';")"
    if [ -z "$ALL_SCHEMAS" ]; then
      echo "[test-db] nothing stale to clean up" >&2
      exit 0
    fi
    FOUND_STALE=0
    while IFS= read -r db; do
      [ -z "$db" ] && continue
      SUFFIX_PART="${db#tiro_testing_}"
      CREATED_EPOCH="${SUFFIX_PART%%_*}"
      if ! [[ "$CREATED_EPOCH" =~ ^[0-9]+$ ]]; then
        echo "[test-db] skipping ${db}: cannot parse creation time from name (not created by this script?)" >&2
        continue
      fi
      AGE_SECONDS=$((NOW_EPOCH - CREATED_EPOCH))
      if [ "$AGE_SECONDS" -ge "$MAX_AGE_SECONDS" ]; then
        FOUND_STALE=1
        echo "[test-db] dropping stale ${db} (age: $((AGE_SECONDS / 3600))h)" >&2
        mysql_exec "DROP DATABASE IF EXISTS \`${db}\`;"
      fi
    done <<< "$ALL_SCHEMAS"
    if [ "$FOUND_STALE" -eq 0 ]; then
      echo "[test-db] nothing stale to clean up" >&2
    fi
    ;;

  *)
    echo "Usage: $0 {create|drop <suffix>|run -- <command>|cleanup-stale [hours]}" >&2
    exit 1
    ;;
esac
