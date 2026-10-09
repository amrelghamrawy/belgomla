# 00 — Session Start

## Read first

1. This file, then every other `rules/*.md` in numeric order.
2. `src-md-files/08-api-contract.md` §16 "Build Order" to see which module comes next.
3. `git log --oneline -15` in `D:\my work` to see what the previous sessions delivered.

## How work is organised

- One feature = one module slice, delivered with its tests, in one commit (or a few focused commits).
- Follow the documents in `src-md-files/` literally. A business rule is referenced by its id (`BR-PR04`, `BR-INV08`); quote the id in code comments and test names when a test exists to enforce that rule.
- If a document and the code disagree, the document wins unless the document is wrong; then fix the document in the same commit and say so.
- Decisions that need the product owner go into `src-md-files/06-open-decisions.md` with a default applied, never left as a silent assumption.

## Environment (Windows, native, no Docker)

- PHP 8.5 at `C:\php`, Composer via `C:/php/composer.bat` (Bash) or `composer` (PowerShell).
- PostgreSQL 16 service `postgresql-x64-16`; app DB `belgomla`, tests DB `belgomla_test`, role `belgomla` / `belgomla_dev`.
- Redis Windows service on 6379.
- Tenant hosts are `{subdomain}.gomal.test`. Smoke-test with `curl --resolve alnoor.gomal.test:8000:127.0.0.1` and an `Origin` header.

## Definition of Done for any feature

1. Code follows `rules/01-testing.md` and the arch tests pass.
2. `composer test` is green in `backend/`.
3. The feature was exercised once against `php artisan serve` with curl (middleware order and session behaviour are not fully covered by in-process tests).
4. `src-md-files/` updated if behaviour or decisions changed.
5. A commit with a message describing the slice, ending with the attribution line the session was given.

## Never

- Never commit `.env`, vendor, or storage files.
- Never bypass tenant scoping with `withoutTenantScope()` outside CLI/platform code without a comment explaining why.
- Never trust `tenant_id` from a request payload or header.
- Never introduce Docker, Sail, or a second database per tenant.
