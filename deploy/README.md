# BelGomla production runbook

One Ubuntu 24.04 VM, no Docker (Tech §1).
- Nginx sits in front of Next.js (`next start`, port 3000) and PHP-FPM (Laravel).
- PostgreSQL 16 and Redis run on the same box.
- Background work runs as systemd units.

Decisions: D44 hosting, D45 phone and accounts, D64 images, D65 alerts, D66 PHP and logging (`src-md-files/06-open-decisions.md`).

```
browser ──https──▶ nginx ─┬─ /up, /api/*, /sanctum/* ──fastcgi (Host kept)──▶ php-fpm (backend/public/index.php)
                          ├─ /storage/*  ──▶ backend/storage/app/public (D61)
                          └─ everything else ──▶ next start :3000 ──SSR prefetch──▶ nginx 127.0.0.1:8081 ──▶ php-fpm
Hypersender ──https──▶ nginx hooks.<domain> ──▶ php-fpm only
systemd: belgomla-queue@1,2 (queue:work redis --queue=messaging,default) · belgomla-next ·
         belgomla-scheduler.timer (1 min) · belgomla-monitor.timer (5 min) · belgomla-backup.timer (01:00 UTC)
```

| Path | What |
| --- | --- |
| `/srv/belgomla` | git checkout, owned by `belgomla` |
| `/etc/belgomla/backend.env` | Laravel `.env` (symlinked as `backend/.env`), secrets |
| `/etc/belgomla/frontend.env` | `BACKEND_HOST/PORT` for Next's server-side prefetch |
| `/etc/belgomla/ops.env` | settings for the scripts in this folder |
| `/etc/belgomla/certbot-dns.ini` | DNS API token for the wildcard certificate |
| `~belgomla/.pgpass`, `~belgomla/.config/rclone/` | database password, backup bucket credentials |
| `backend/storage/logs/` | `laravel-*.log` (errors), `monitor-*.log` (alerts), `messages.log` (log driver only) |

**Secrets never go into git.** The `env/*.example` files are templates. The `/etc/belgomla` files are mode 640, owner root, group belgomla.

## First install

1. **DNS** (at the provider chosen in D44): create
   - `A gomalecommerce.app` → the VM IP;
   - `A *.gomalecommerce.app` → the VM IP.

   The wildcard also covers `hooks.`.
2. **SSH in as a sudo user**, clone the repo anywhere, and create `/etc/belgomla/ops.env` from `env/ops.env.example`.
   - Set `CERTBOT_EMAIL` and the `DNS_PLUGIN` of your DNS provider.
   - Put that provider's API token in `/etc/belgomla/certbot-dns.ini` (format: the plugin's docs). Make the file mode 600.
3. **Provision:**

   ```bash
   sudo REPO_URL=git@github.com:<org>/belgomla.git DB_PASSWORD="$(openssl rand -hex 24)" bash deploy/provision.sh
   ```

   - Note the DB password: it goes into `backend.env` next.
   - Give the `belgomla` user a deploy key for the repository first, or use an HTTPS URL with a read token.
4. **Fill `/etc/belgomla/backend.env`:**
   - `APP_KEY`: run `cd /srv/belgomla/backend && sudo -u belgomla php artisan key:generate --show` once `composer install` has run. On the very first install, run `sudo -u belgomla composer install --no-dev` there first.
   - `DB_PASSWORD`.
   - `MESSAGING_WEBHOOK_SECRET`: `openssl rand -hex 32`.
   - The `HYPERSENDER_*` values.
   - `MONITOR_ALERT_PHONE`.
5. **Backup bucket:**
   - Run `sudo -u belgomla rclone config` and create an S3 remote named as in `BACKUP_REMOTE` (`belgomla-s3`). Use the provider's endpoint and keys, and create the bucket.
   - Check it with `sudo -u belgomla rclone lsd belgomla-s3:`.
6. **Deploy:** `sudo -u belgomla /srv/belgomla/deploy/deploy.sh`. It ends with the post-deploy assertions.
7. **First tenant** (Tech §18; with `SMOKE_TENANT=alnoor` the deploy checks expect it):

   ```bash
   cd /srv/belgomla/backend
   sudo -u belgomla php artisan tenant:create alnoor "مكتبة النور" --owner-email=<owner email> --owner-password='<temporary password>' --vertical=school-books
   ```

   - Pass both options: without them the command prompts.
   - The owner changes the password at the first login (dashboard → settings → users).
   - Run `deploy.sh` once more to see every check green.
8. **Hypersender** (tasks/4 live check):
   - Pair the WhatsApp instance to the platform number (D48).
   - Install the SMS app on the platform Android phone (D49).
   - On both instances, set the webhook URL to `https://hooks.gomalecommerce.app/api/webhooks/hypersender/whatsapp` (or `/sms`), with the custom header `Authorization: <MESSAGING_WEBHOOK_SECRET>`.
   - The SMS app's heartbeat then shows up in `sms_phone_heartbeats` within minutes.
9. **External uptime check:** watch `https://alnoor.gomalecommerce.app/up` from outside with an uptime service (UptimeRobot, Better Stack, …), alerting the operator. `monitor.sh` runs on the VM itself, so it cannot report the VM being down.

## Deploy

```bash
sudo -u belgomla /srv/belgomla/deploy/deploy.sh                    # main
sudo -u belgomla DEPLOY_REF=<tag-or-branch> /srv/belgomla/deploy/deploy.sh
```

The script runs these steps in order:
1. git pull;
2. `composer install --no-dev`;
3. `migrate --force`;
4. config, route, view and event caches;
5. `npm ci && npm run build`;
6. reload php-fpm, restart the queue workers and Next;
7. the assertions:
   - `tenant:list`;
   - `/up` on hooks and the smoke tenant;
   - `/api/store/tenant`;
   - the storefront `/`;
   - a GET on the webhook route answering 405 (Laravel, not Next).

Expect a short blip while the services restart; V1 does not require zero downtime.

**Rollback:** `DEPLOY_REF=<previous commit> deploy.sh`. Migrations are not rolled back automatically. A migration that must be undone needs `php artisan migrate:rollback --step=1` before the redeploy; `DB::prohibitDestructiveCommands` blocks `migrate:fresh`/`reset` in production on purpose.

Change `TENANT_BASE_DOMAIN` only together with a redeploy: `route:cache` bakes the hooks domain in.

## Monitoring and alerts

`belgomla-monitor.timer` runs `monitor.sh` every 5 minutes. It checks:
- `/up` on hooks and every active tenant host (through the local nginx, TLS included);
- the storefront page;
- the queue, Next, nginx, Redis and PostgreSQL units;
- the last backup run.

It hands the failures to `php artisan platform:monitor`, which adds its own checks:
- messaging queue backlog over 50;
- failed jobs in the last hour;
- free disk under 10%;
- no SMS-phone heartbeat for 2 hours, when `MESSAGING_DRIVER=hypersender` (D49).

Alerts:
- **WhatsApp to `MONITOR_ALERT_PHONE`** (with SMS fallback), sent through Messenger as `MONITOR_ALERT_TENANT` (D65).
- **Every alert also goes to `storage/logs/monitor-*.log`.**
- A problem is re-sent every 60 minutes while it stays open, and one "✅" message reports recovery.
- If WhatsApp itself is the failure, the SMS fallback still applies.
- If both channels are down, the log and the external uptime service remain.

Handy commands:

```bash
systemctl list-timers 'belgomla-*'
journalctl -u belgomla-monitor -n 50          # last monitor runs
journalctl -u 'belgomla-queue@*' -f           # workers
sudo -u belgomla php artisan queue:failed     # then queue:retry <id> or queue:forget <id>
tail -f /srv/belgomla/backend/storage/logs/laravel-$(date -u +%F).log
```

## Backups and restore

`backup.sh` runs nightly. It writes `pg_dump -Fc` plus a tar of `storage/app/public`, with SHA256SUMS, to `BACKUP_REMOTE/<UTC stamp>/`, and deletes copies older than 30 days.

**Run it by hand:** `sudo systemctl start belgomla-backup && journalctl -u belgomla-backup -n 20`.

**Restore drill** (go-live checklist; repeat after major changes):

```bash
sudo -u belgomla /srv/belgomla/deploy/restore.sh latest
```

This restores into the database `belgomla_restore_drill` and `/tmp/belgomla-restore/<stamp>/public`, then prints the tenant, product and order counts. Compare them with production, then drop the drill database as the script says. Record the date and the stamp in `tasks/11-release.md`.

**Real restore** (data loss or a new VM):

```bash
sudo systemctl stop belgomla-next belgomla-queue@1 belgomla-queue@2 belgomla-monitor.timer
sudo -u belgomla /srv/belgomla/deploy/restore.sh <stamp> --production
sudo -u belgomla /srv/belgomla/deploy/deploy.sh     # migrations, caches, restart
sudo systemctl start belgomla-monitor.timer
```

On a new VM: provision, fill `/etc/belgomla`, configure rclone, then the commands above.

## Routine operations

| Task | Command |
| --- | --- |
| List tenants | `sudo -u belgomla php artisan tenant:list` |
| Suspend / reactivate a tenant | `php artisan tenant:suspend <sub>` / `tenant:activate <sub>` |
| Rotate the webhook secret | new `MESSAGING_WEBHOOK_SECRET` in `backend.env` → `php artisan config:cache` + reload php-fpm → update both Hypersender instances |
| Renew TLS | automatic (certbot timer, reloads nginx); check with `sudo certbot renew --dry-run` |
| Production smoke tenant | `tenant:create smoke ...`, run the flows, then `tenant:suspend smoke` (go-live checklist) |

## First tenant data entry (bulk import is V2)

Plan one session with the merchant before go-live:
- Agree on the category tree and the custom fields. The school-books vertical template seeds grade and subject.
- Agree on the units (carton, piece) and the price tiers per product.
- Collect product photos (≤ 4 MB each) named by SKU.
- Enter the first 20 products together in the dashboard so the merchant learns the flow, and leave the rest to them.
- Check the storefront on a phone afterwards.
