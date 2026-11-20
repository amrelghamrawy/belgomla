# V2 Task 17 — V2 release

Status: **planned**. Requires V2 Tasks 01–16. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Ship V2 to production safely and leave the documentation describing V2 as the current product, the way Task 11 did for V1.

## Deliverables

### 1. Documentation

- `src-md-files/03-v1-v2-roadmap.md`: V2 items delivered are marked done with the task number; deferred items listed under "V2 deferred" (see the board).
- `01-product-scope.md`, `05-module-scope.md` (move the V2 modules into the main list), `07-domain-model.md` §15 (hooks now used → removed; new hooks for the deferred items if any).
- `06-open-decisions.md`: D74–D94 present with their final state; resolved ones moved to the resolved table.
- `rules/00-session-start.md`: build order now points at V2 done; any new production step (new queue, scheduled commands `orders:expire-pending`, `pricing:promotions-tick`; new env keys).
- `CLAUDE.md` quick facts, if anything changed (new dependency openspout).

### 2. Data and migrations

- Migration rehearsal: restore the latest production backup into a local database, run every V2 migration (notably Task 13's two-step unit migration and Task 08's backfill), run `composer test` against the code, and compare the counts and totals before/after (orders, order totals, stock, outstanding) with a script kept in `deploy/`.
- Downtime plan: V2 migrations that rewrite large tables run inside a maintenance window (`php artisan down` with the storefront message), documented in `deploy/README.md`.

### 3. Demo data

`tenant:demo` seeders (`database/seeders`, through actions only, rules/02 §1) show the V2 features: returns, a credit customer with an overdue balance, two customer groups and a negotiated price, an active promotion, a carton unit, a variant product, a grade-set bundle, a pre-order product, staff users of each role.

### 4. Release checks

- [ ] `composer test` green; `npm run typecheck && npm run lint && npm test && npm run test:e2e` green on the release commit.
- [ ] OpenAPI regenerated and the types committed.
- [ ] Deploy to production with `deploy.sh`; post-deploy assertions green; the new scheduled commands visible in `schedule:list`.
- [ ] Production smoke: each V2 flow once on the first tenant (with the merchant where it involves their data).
- [ ] Monitor and backups still green the next morning.
- [ ] Lighthouse re-measured on the storefront (variants/bundles/promotions added weight).

## Definition of done

Every box ticked, the board updated with the commit, and a short "V2 delivered" note at the top of `tasks/v2/README.md` with the date.
