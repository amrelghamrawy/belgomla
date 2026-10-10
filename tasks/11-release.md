# Task 11 — Release (infrastructure, seed data, go-live checklist)

Status: **repo side done (2026-10-10), go-live pending.** Everything that lives in git is built and tested. The go-live checklist below is executed on the production VM, which does not exist yet (D44). Requires Tasks 1–10. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

A reproducible production deployment on one Linux VM without Docker, the first real tenant provisioned, and the operational runbook.

## Read before coding

| What | Where                                                                                           |
| ---- | ----------------------------------------------------------------------------------------------- |
| Tech | `04-technical-constraints.md` §1 (no Docker), §5 hosts, §6 cookies, §7 TLS wildcard, §13 webhooks, §18 CLI |
| Ops  | `deploy/README.md` (the runbook), `backend/README.md`, `rules/00-session-start.md` environment and production sections |

## Deliverables

1. `deploy/` folder in the repo:
   - `provision.sh` (Ubuntu 24.04 LTS), covering:
     - PHP **8.5** FPM from the ondrej PPA (D66, the version the tests run on), with pgsql, gd, intl, bcmath, mbstring, xml, zip, curl and opcache; Redis goes through predis, so no extension is needed;
     - Nginx, PostgreSQL 16 (+ `pg_trgm`), Redis on localhost, Node 24;
     - Certbot with a DNS challenge for `*.gomalecommerce.app` and the apex;
     - ufw 22/80/443 and unattended security updates;
     - the `belgomla` user, `/etc/belgomla` for secrets, and a sudoers entry limited to BelGomla's services.
   - `nginx/belgomla.conf` + `nginx/snippets/` route traffic as follows:
     - `server_name *.gomalecommerce.app`: `/up`, `/api/` and `/sanctum/` go to PHP-FPM, and `/storage/` is served from `backend/storage/app/public` (D61). Both come before the Next.js `location /` (port 3000). `Host` is kept: fastcgi passes the request's Host as `HTTP_HOST`.
     - `hooks.gomalecommerce.app` goes to Laravel only.
     - The apex returns 404.
     - An internal `127.0.0.1:8081` listener serves Next's server-side prefetch (`BACKEND_HOST=127.0.0.1 BACKEND_PORT=8081`).
     - HTTP redirects to HTTPS; security headers, 4 MB bodies, gzip, and brotli when it is packaged.
     - Next's `app/api`, `app/sanctum` and `app/storage` route handlers are never reached in production.
   - `php-fpm/belgomla.conf`: a pool running as `belgomla` on `/run/php/belgomla.sock`.
   - `systemd/` units:
     - `belgomla-queue@.service`: `queue:work redis --queue=messaging,default --tries=3 --backoff=10`, two instances. **The queue list is required**: every V1 job runs on the `messaging` queue.
     - `belgomla-scheduler.timer` (every minute, `schedule:run`) and `belgomla-next.service` (`next start`).
     - `belgomla-monitor.timer` (5 min) and `belgomla-backup.timer` (nightly).
   - `deploy.sh`, in order:
     1. git pull;
     2. `composer install --no-dev --optimize-autoloader`;
     3. `php artisan migrate --force`;
     4. `config:cache`, `route:cache`, `view:cache`, `event:cache`;
     5. `npm ci && npm run build`;
     6. restart the services;
     7. the post-deploy assertions.

     Zero downtime is **not** required in V1.
   - `backup.sh` and `restore.sh`:
     - nightly `pg_dump` + `storage/app/public` to an S3-compatible bucket (rclone), kept for 30 days;
     - restore as a drill into a scratch database, or `--production`;
     - the procedure is in `deploy/README.md`. The drill is on the checklist.
2. Backend production settings are in `deploy/env/backend.env.example`:
   - `APP_ENV=production`, `APP_DEBUG=false`;
   - `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN` empty (host-only, Tech §6);
   - `SANCTUM_STATEFUL_DOMAINS=*.gomalecommerce.app`, `TENANT_BASE_DOMAIN=gomalecommerce.app`;
   - `REDIS_CLIENT=predis`;
   - product images on the local `public` disk with `PUBLIC_DISK_URL=/storage`, backed up to S3 (**D64**; the old "FILESYSTEM_DISK=s3" line was never read by the code);
   - logging to daily files, with no Sentry in V1 (D66);
   - `DB::prohibitDestructiveCommands` was already on.
3. Health and monitoring:
   - `/up` now checks PostgreSQL and Redis.
   - `monitor.sh` runs every 5 minutes. It checks `/up` per active tenant host and hooks, the storefront, the systemd units and the last backup.
   - It then calls `php artisan platform:monitor`, which adds queue backlog, failed jobs, disk, and the Hypersender SMS phone heartbeat (alert after 2 h without one; heartbeats are now stored, D49).
   - Alerts go over WhatsApp to the operator through Messenger (purpose `system_alert`), sent as `MONITOR_ALERT_TENANT` (**D65**), and are always written to `logs/monitor-*.log`.
   - An external uptime service covers the VM being down.
4. First tenant: `php artisan tenant:create alnoor "مكتبة النور" --owner-email=... --owner-password=... --vertical=school-books`. Import the initial catalog manually (bulk import is V2); plan the data-entry session with the merchant (`deploy/README.md`).
5. The go-live checklist (below) is ticked during execution.

## Tests

No new automated tests for the scripts. The gate is the checklist plus a recorded production smoke run. `deploy.sh` ends with post-deploy assertions:
- `php artisan tenant:list`;
- `/up` on hooks and the smoke tenant;
- `/api/store/tenant`;
- the storefront `/`;
- a GET on the webhook route answering 405 from Laravel.

The backend additions are covered by Pest tests:
- `/up` (healthy, Redis down);
- heartbeat storage;
- each monitor check;
- alert dedupe, re-alert and recovery;
- the missing-config and failed-send paths;
- the schedule.

## Delivery notes (2026-10-10)

- `225aef7` backend slice:
  - `Platform\Services\HealthProbe` (`/up`);
  - `Messaging\Actions\RecordPhoneHeartbeat` + `sms_phone_heartbeats` + `LastPhoneHeartbeatQuery`;
  - `platform:monitor` (`Platform\Services\HealthChecks`, `Platform\Actions\AlertOperator`, `config/monitor.php`);
  - `Tenancy\Queries\TenantBySubdomainQuery`;
  - the scheduler prunes failed jobs after 30 days;
  - `.env.example` aligned with the real local stack.
- `composer test` is green: lint, PHPStan max, type coverage 100%, and 2,703 Pest tests including arch.
- Smoke-tested against `php artisan serve`:
  - `/up` returns 200 on the tenant and hooks hosts, and 500 with Redis unreachable;
  - the heartbeat webhook returns 200 and stores the row; with a wrong secret it returns 401;
  - `platform:monitor` alerted through Messenger, the Redis queue and `queue:work redis --queue=messaging,default` to the log driver;
  - a second run was deduped.
- The local machine really had 9% free disk, and the monitor reported it, so the tests pin the disk threshold.
- `a7c3884`: the `deploy/` folder. All scripts pass `bash -n`. **Not verified here:** `nginx -t`, shellcheck and a real run of `provision.sh`. There is no Linux host, WSL or shellcheck on the dev machine. These happen on the VM (checklist).
- Changed against the original task text, with the reasons:
  - PHP 8.5 instead of 8.3 (D66);
  - images local + S3 backup instead of the `s3` disk (D64);
  - the worker queue list;
  - Sentry not installed (D66);
  - alert routing through an operator tenant (D65);
  - a separate `monitor.sh`, not cron;
  - D44/D45 moved into `06-open-decisions.md`, and D45 now points at D48/D49 (not D30/D31).

## Go-live checklist (tick on the VM; record dates and evidence)

- [ ] D44 decided: provider, VM, bucket, DNS provider; VM created (2 vCPU / 4 GB, Ubuntu 24.04)
- [ ] DNS: `A gomalecommerce.app` and `A *.gomalecommerce.app` → VM IP
- [ ] `provision.sh` ran clean; `nginx -t` ok; TLS issued for the apex + wildcard (`certbot certificates`); `certbot renew --dry-run` ok
- [ ] `/etc/belgomla/*.env` filled (APP_KEY, DB, webhook secret, Hypersender, MONITOR_*); nothing secret in git
- [ ] `deploy.sh` green on the release commit (post-deploy assertions pass)
- [ ] `composer test` green on the release commit (`npm run typecheck && npm run lint && npm test` too)
- [ ] Hypersender paid plan active (the trial is 14 days, D47); WhatsApp instance paired to the dedicated platform number (D48)
- [ ] Platform Android phone with its SIM running the Hypersender SMS app, charged, on Wi-Fi, heartbeat visible in the Hypersender dashboard **and** in `sms_phone_heartbeats` (D49); where it lives is recorded (D45)
- [ ] Webhook URLs on `https://hooks.gomalecommerce.app/api/webhooks/hypersender/{whatsapp,sms}` registered in Hypersender with the `Authorization` header
- [ ] tasks/4 live check: one real OTP over WhatsApp and one over SMS, delays recorded in `tasks/4-messaging.md`
- [ ] Smoke test of every flow on production with a test tenant, suspended afterwards:
  - [ ] register (OTP), login;
  - [ ] browse/filter, cart, checkout;
  - [ ] dashboard login, order transitions, collection, reports;
  - [ ] invite a customer.
- [ ] Monitor: an alert received on WhatsApp (e.g. `platform:monitor --report=test=go-live check`) and its recovery message
- [ ] External uptime service watching `https://alnoor.gomalecommerce.app/up`
- [ ] Backup ran (`systemctl start belgomla-backup`), files visible in the bucket; restore drill done (`restore.sh latest`, counts match)
- [ ] First tenant `alnoor` created; data-entry session with the merchant held; storefront checked on a phone
- [ ] Lighthouse mobile on `/c/[slug]` re-measured on the production stack (tasks/9 open item, target ≥ 90)
- [ ] `rules/00-session-start.md` production notes still accurate (no secrets)

## Definition of done

- Production serves `https://alnoor.gomalecommerce.app` end-to-end.
- The checklist is fully ticked.
- Secrets are stored outside git.
- This file is marked done with the release commit hash and date.

## Open questions

- D44: hosting provider, VM size, object storage and DNS provider (defaults in `06-open-decisions.md`).
- D45: who holds the Hypersender account, the dedicated WhatsApp number and the Android phone + SIM (ties to D48/D49). The phone is a production asset: where does it physically live?
