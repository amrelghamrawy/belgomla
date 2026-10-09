# Task 11 — Release (infrastructure, seed data, go-live checklist)

Status: **not started**. Requires Tasks 1–10. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

A reproducible production deployment on one Linux VM without Docker, the first real tenant provisioned, and the operational runbook.

## Read before coding

| What | Where                                                                                           |
| ---- | ----------------------------------------------------------------------------------------------- |
| Tech | `04-technical-constraints.md` §1 (no Docker), §5 hosts, §6 cookies, §7 TLS wildcard, §13 webhooks, §18 CLI |
| Ops  | `backend/README.md`, `rules/00-session-start.md` environment section                              |

## Deliverables

1. `deploy/` folder in the repo:
   - `provision.sh` (Ubuntu LTS): PHP 8.3 FPM + extensions (pgsql, redis via predis so no ext needed, gd, intl, bcmath), Nginx, PostgreSQL 16, Redis, Node LTS, Certbot with DNS challenge for `*.gomalecommerce.app`, firewall (ufw: 22/80/443), unattended security updates.
   - `nginx/tenant.conf`: `server_name *.gomalecommerce.app` → Next.js (port 3000) with `/api` and `/sanctum` proxied to PHP-FPM preserving `Host`; `hooks.gomalecommerce.app` → Laravel only; HTTP→HTTPS; security headers; client body size for uploads 4 MB; gzip/brotli.
   - `systemd/`: `belgomla-queue@.service` (Horizon is not used; plain `queue:work redis --tries=3 --backoff=10` × 2 workers), `belgomla-scheduler.timer` (every minute, `schedule:run`), `belgomla-next.service` (`next start`).
   - `deploy.sh`: git pull → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force` → `config:cache route:cache view:cache` → `npm ci && npm run build` → restart services; zero-downtime is **not** required in V1.
   - `backup.sh`: nightly `pg_dump` + `storage/app/public` to an S3-compatible bucket; retention 30 days; restore procedure documented and tested once.
2. Backend production settings: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS=*.gomalecommerce.app`, `TENANT_BASE_DOMAIN=gomalecommerce.app`, `FILESYSTEM_DISK=s3` for product images (`04` §1) with `public` URL base, logging to daily files + optional Sentry DSN; `DB::prohibitDestructiveCommands` already on.
3. Health and monitoring: `/up` wired to Nginx; a `monitor.sh` cron that checks `/up` per tenant host, queue backlog, disk; alerts via email-free channel (WhatsApp to the operator through the Messaging module's `Messenger` with a `system_alert` template — add the template to D30's list) or a simple log + external uptime service.
4. First tenant: `php artisan tenant:create alnoor "مكتبة النور" --owner-email=... --owner-password=... --vertical=school-books`; import the initial catalog manually (bulk import is V2) — plan the data entry session with the merchant.
5. Go-live checklist (in this file, ticked during execution): DNS wildcard A record; TLS issued; webhooks registered at Meta and SMS Misr with the `hooks` host URLs; WhatsApp templates approved; SMS sender name approved; `composer test` green on the release commit; smoke test of every flow on production with a test tenant that is suspended afterwards; backup restore drill done; `rules/00-session-start.md` updated with production access notes (no secrets in the repo).

## Tests

No new automated tests; the gate is the checklist plus a recorded production smoke run. Add a `php artisan tenant:list` and `/up` check to `deploy.sh` as a post-deploy assertion.

## Definition of done

Production serves `https://alnoor.gomalecommerce.app` end-to-end; checklist fully ticked; secrets stored outside git; this file marked done with the release commit hash and date.

## Open questions

- D44: Hosting provider and VM size (default: 2 vCPU / 4 GB, Hetzner or DigitalOcean) and object storage (default: provider's S3-compatible).
- D45: Who holds the Meta Business and SMS Misr accounts (ties to D30/D31).
