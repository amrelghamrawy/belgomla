---
description: Run the Definition of Done before a push or before saying a feature is done
argument-hint: "[--all | --base <ref>]"
---

# Preflight: the Definition of Done, checked rather than claimed

Runs `rules/00-session-start.md` "Definition of Done" in order. Each stage is cheaper than the next; do not skip ahead. Arguments: `$ARGUMENTS` (empty = changes since the merge-base with `main`).

## 1. The automated gate

```bash
bash scripts/preflight.sh $ARGUMENTS
```

The same script runs from the `pre-push` hook, so whatever is red here blocks the push anyway. It picks the suites by what changed: `composer test` for `backend/`, the OpenAPI sync check, typecheck + lint + vitest (+ `next build` for routing/config) for `frontend/`, typecheck + vitest for `wa-gateway/`.

Red = fix the code, not the test, when the test follows the spec (`rules/01` §6.4). Rerun only what failed while fixing, then the script once more at the end. Never `--no-verify`, never `SKIP_PREFLIGHT`.

## 2. The live smoke (DoD item 3), when the backend changed

Required when the change touches an endpoint, middleware, session, cookie, upload or webhook (`rules/01` §6.5). It is not required for a refactor that leaves every route's behaviour the same; say so in the report.

```bash
cd backend && php artisan serve          # in the background; needs backend/.env and a seeded DB
bash scripts/smoke.sh login              # owner@alnoor.test / password through Sanctum CSRF + session
bash scripts/smoke.sh call GET  /api/dashboard/<path>
bash scripts/smoke.sh call POST /api/dashboard/<path> '{"field":"value"}'
bash scripts/smoke.sh login-customer <phone> <password>   # storefront side
```

For each endpoint the change touched: one happy path, one refusal (validation, 409 transition, or 403), and the number the spec fixes, checked against the business rule rather than against what the code printed (`rules/04-business-truth.md` §4). Write the calls and their answers into the task file under "Manual checks", the way `tasks/7-collections-reports.md` does.

If `php artisan serve` cannot start (no `.env`, database unreachable), say so in the report and stop this stage; do not mark it done.

## 3. E2E, when a user flow changed

`cd frontend && npm run test:e2e` when a storefront or dashboard flow changed (`rules/03` §6). It needs `php artisan serve` + a `migrate:fresh --seed` database.

## 4. Documents (DoD item 4)

- Behaviour or a rule changed → the matching `src-md-files/` section, in the same commit.
- A default you chose where the spec was silent → an entry in `src-md-files/06-open-decisions.md`, not a silent assumption.
- The task file's status and checklist are up to date.

## 5. Report

End with this table in the reply, one row per stage, each **PASS**, **FIXED** (what, six words max), **N/A** (one-line reason) or **NOT RUN** (why). A blank row means the stage was skipped, and a skipped stage is not a pass.

| Stage | Result |
|---|---|
| preflight.sh | |
| live smoke | |
| e2e | |
| documents | |

Then: the test count delta and the rule ids covered (`rules/01` §6), and anything left open.
