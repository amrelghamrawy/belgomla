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
- Tenant hosts are `{subdomain}.gomal.test`. Smoke-test with `scripts/smoke.sh` (`login`, then `call METHOD /api/...`): it does the `--resolve`, `Origin`, CSRF cookie and session for you.
- Git hooks are tracked in `scripts/hooks/`. Once per clone: `git config core.hooksPath scripts/hooks`. `pre-push` runs `scripts/preflight.sh` on the pushed range and refuses a red push.

## Production (tasks/11, `deploy/README.md`)

- One Ubuntu 24.04 VM at DigitalOcean (D44). Platform domain `belgomla.app`: stores on `{subdomain}.belgomla.app`, webhooks on `hooks.belgomla.app`. App in `/srv/belgomla` as user `belgomla`; secrets only in `/etc/belgomla/*.env`, `~belgomla/.pgpass` and the rclone config, never in git.
- Deploy: `sudo -u belgomla /srv/belgomla/deploy/deploy.sh` (ends with post-deploy assertions). Tenants: `php artisan tenant:*` in `/srv/belgomla/backend`.
- Store WhatsApp numbers (D70): `belgomla-wa-gateway` on `127.0.0.1:3100`, built and restarted by `deploy.sh`, sessions in `/var/lib/belgomla-wa-gateway`.
- Logs: `backend/storage/logs/laravel-*.log`, `monitor-*.log`; `journalctl -u 'belgomla-*'`. Alerts reach the operator on WhatsApp (D65).
- SSH access, the VM address and the Hypersender account owner are recorded with the platform owner (D45), not here.
- Anything changed on the VM by hand must be reflected in `deploy/` in the same session.

## Definition of Done for any feature

1. Code follows `rules/01-testing.md` and the arch tests pass; calculations and "today the code does" claims follow `rules/04-business-truth.md`.
2. `bash scripts/preflight.sh` is green (it runs `composer test` and the frontend/wa-gateway suites for whatever changed).
3. The feature was exercised once against `php artisan serve` with `scripts/smoke.sh` (middleware order and session behaviour are not fully covered by in-process tests).
4. `src-md-files/` updated if behaviour or decisions changed.
5. A commit with a message describing the slice, ending with the attribution line the session was given.

`/preflight` walks these in order and ends with a PASS / FIXED / N/A / NOT RUN table. A feature is not called done without that table.

## Never

- Never commit `.env`, vendor, or storage files.
- Never bypass tenant scoping with `withoutTenantScope()` outside CLI/platform code without a comment explaining why.
- Never trust `tenant_id` from a request payload or header.
- Never introduce Docker, Sail, or a second database per tenant.
- Never skip the push gate (`--no-verify`, `SKIP_PREFLIGHT=1`, changing `core.hooksPath`). Those are the human's escape hatch; a red preflight is fixed, not bypassed.
