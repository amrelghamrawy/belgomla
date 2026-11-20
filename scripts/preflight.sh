#!/usr/bin/env bash
# preflight.sh: the automated part of the Definition of Done (rules/00-session-start.md).
#
# One source of truth: the pre-push hook (scripts/hooks/pre-push) and the /preflight
# command (.claude/commands/preflight.md) both run this file. Change the checks here.
#
# It runs only the suites whose area changed since <base>:
#   backend/     composer test (lint, phpstan, type coverage, pest incl. arch)
#   openapi      api:openapi + api:types must reproduce the committed files exactly
#   frontend/    typecheck, lint, vitest with the coverage floor; next build when routing/config changed
#   audits       composer audit / npm audit (prod, high+) when a lockfile changed
#   wa-gateway/  typecheck, vitest
#
# Usage:
#   scripts/preflight.sh                 # changes since the merge-base with main (committed + working tree)
#   scripts/preflight.sh --base <ref>    # changes since <ref>
#   scripts/preflight.sh --all           # every suite, whatever changed
#
# Exit code 0 = every selected suite green. Anything else = do not push.

set -uo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT" || exit 1

# PHP is not on Git Bash's PATH on this machine (rules/00: PHP 8.5 at C:\php).
PHP_DIR="${PHP_DIR:-/c/php}"
if ! command -v php >/dev/null 2>&1 && [ -x "$PHP_DIR/php.exe" ]; then
    export PATH="$PHP_DIR:$PATH"
fi
if [ -f "$PHP_DIR/composer.phar" ]; then
    COMPOSER=(php "$PHP_DIR/composer.phar")
else
    COMPOSER=(composer)
fi

BASE=""
ALL=0
while [ $# -gt 0 ]; do
    case "$1" in
        --base) BASE="${2:-}"; shift 2 ;;
        --all) ALL=1; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 64 ;;
    esac
done

if [ -z "$BASE" ]; then
    BASE="$(git merge-base HEAD origin/main 2>/dev/null || git merge-base HEAD main 2>/dev/null || echo "")"
fi

# Committed changes since BASE, plus anything staged, unstaged or untracked.
changed_files() {
    {
        [ -n "$BASE" ] && git diff --name-only "$BASE" HEAD
        git diff --name-only HEAD
        git ls-files --others --exclude-standard
    } | sort -u
}

CHANGED="$(changed_files)"
touched() { [ "$ALL" -eq 1 ] || grep -Eq "$1" <<<"$CHANGED"; }

if [ -n "$(git status --porcelain)" ]; then
    echo "⚠ the working tree has uncommitted changes: the suites test them too, but a push sends only commits."
fi

declare -a NAMES=() RESULTS=()
FAILED=0

run() {
    local name="$1"; shift
    echo
    echo "━━ $name"
    echo "   \$ $*"
    local start=$SECONDS
    if "$@"; then
        RESULTS+=("PASS  $((SECONDS - start))s")
    else
        RESULTS+=("FAIL  $((SECONDS - start))s")
        FAILED=1
    fi
    NAMES+=("$name")
}

skip() {
    NAMES+=("$1")
    RESULTS+=("skip  ($2)")
}

# Regenerates into a temp dir and compares; never writes the working tree (another
# session may be editing it). Line endings are ignored: Windows checkouts are CRLF.
openapi_in_sync() {
    local tmp
    tmp="$(mktemp -d)"
    # The RETURN trap is global: clear it on first use, or it fires again when run() returns
    # (tmp gone by then) and set -u kills the script.
    trap 'rm -rf "$tmp"; trap - RETURN' RETURN
    (cd backend && php artisan api:openapi --output="$tmp/openapi.json" >/dev/null) || return 1
    (cd frontend && npx --no-install openapi-typescript "$tmp/openapi.json" -o "$tmp/schema.d.ts" >/dev/null 2>&1) || return 1
    local drift=0
    diff -q --strip-trailing-cr "$tmp/openapi.json" backend/openapi.json >/dev/null || { echo "backend/openapi.json is stale"; drift=1; }
    diff -q --strip-trailing-cr "$tmp/schema.d.ts" frontend/lib/api/schema.d.ts >/dev/null || { echo "frontend/lib/api/schema.d.ts is stale"; drift=1; }
    [ "$drift" -eq 0 ] || echo "run: cd backend && php artisan api:openapi; cd ../frontend && npm run api:types; then commit both."
    return "$drift"
}

# --- backend --------------------------------------------------------------
if touched '^backend/'; then
    run "backend: composer test" bash -c "cd backend && ${COMPOSER[*]} test"
else
    skip "backend: composer test" "backend/ unchanged"
fi

# --- dependency audits (known CVEs; needs the network) ----------------------
if touched '^backend/composer\.(json|lock)$'; then
    run "backend: composer audit" bash -c "cd backend && ${COMPOSER[*]} audit --locked --no-interaction"
else
    skip "backend: composer audit" "composer.lock unchanged"
fi
if touched '^frontend/package(-lock)?\.json$'; then
    run "frontend: npm audit (prod, high+)" bash -c "cd frontend && npm audit --omit=dev --audit-level=high"
else
    skip "frontend: npm audit" "package-lock.json unchanged"
fi

# --- API contract ----------------------------------------------------------
if touched '^(backend/openapi/|backend/openapi\.json|frontend/lib/api/schema\.d\.ts)'; then
    run "openapi: yaml → json → schema.d.ts in sync" openapi_in_sync
else
    skip "openapi: in sync" "spec unchanged"
fi

# --- frontend --------------------------------------------------------------
if touched '^frontend/'; then
    run "frontend: typecheck" bash -c "cd frontend && npm run --silent typecheck"
    run "frontend: lint" bash -c "cd frontend && npm run --silent lint"
    run "frontend: vitest + coverage floor" bash -c "cd frontend && npm run --silent test:coverage"
    # rules/03 §6: `npm run build` for anything touching routing/config.
    if touched '^frontend/(app/|proxy\.ts|next\.config\.|package(-lock)?\.json|tsconfig)'; then
        run "frontend: next build" bash -c "cd frontend && npm run --silent build"
    else
        skip "frontend: next build" "no routing/config change"
    fi
else
    skip "frontend" "frontend/ unchanged"
fi

# --- wa-gateway ------------------------------------------------------------
if touched '^wa-gateway/'; then
    run "wa-gateway: typecheck" bash -c "cd wa-gateway && npm run --silent typecheck"
    run "wa-gateway: vitest" bash -c "cd wa-gateway && npm test --silent"
else
    skip "wa-gateway" "wa-gateway/ unchanged"
fi

# --- summary ---------------------------------------------------------------
echo
SCOPE="base: ${BASE:-none}"
[ "$ALL" -eq 1 ] && SCOPE="$SCOPE, --all"
echo "━━ preflight summary ($SCOPE)"
for i in "${!NAMES[@]}"; do
    printf '  %-44s %s\n' "${NAMES[$i]}" "${RESULTS[$i]}"
done
echo
echo "Not automated (see /preflight): curl smoke against php artisan serve, e2e for user flows, src-md-files updates."

if [ "$FAILED" -ne 0 ]; then
    echo "✗ preflight failed"
    exit 1
fi
echo "✓ preflight green"
