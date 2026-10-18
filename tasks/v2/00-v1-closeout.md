# V2 Task 00 — V1 close-out (gate before V2)

Status: **in progress** (2026-10-19: every repo-side item done; the rest needs the VM and the accounts of D44/D45/D48/D49). Requires nothing. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

V2 work starts on a live V1. This task does not specify new behaviour. It collects every V1 item still open and points to the file that owns it. When all boxes below are ticked, V2 task 01 may start.

## Open items

### Task 11 — go-live (`tasks/11-release.md`, "Go-live checklist")

- [ ] D44: choose the provider, create the VM and the bucket, set up DNS for the apex + wildcard.
- [ ] `deploy/provision.sh`, nginx, wildcard TLS (certbot DNS plugin).
- [ ] `/etc/belgomla/*.env` filled; `deploy.sh` green with its post-deploy assertions.
- [ ] `composer test` and `npm run typecheck && npm run lint && npm test` green on the release commit.
- [ ] D48: Hypersender paid plan, platform number paired. D49/D45: SMS Android phone online, heartbeat visible.
- [ ] Webhook URLs registered at Hypersender.
- [ ] Production smoke of every flow (register → OTP → cart → checkout → merchant confirm → deliver → collect).
- [ ] `platform:monitor` alert received on WhatsApp, plus its recovery message (D65). External uptime check on `/up`.
- [ ] Backup and restore drill (`backup.sh`, D64 images included).
- [ ] First tenant (`alnoor`) created; data-entry session held with the merchant.

### Task 4 — live messaging check (`tasks/4-messaging.md`)

- [ ] One real OTP over WhatsApp and one over SMS on production; record the delays in the task file.

### Task 9 — Lighthouse (`tasks/9-storefront-ui.md`)

- [ ] Mobile Lighthouse on `/c/[slug]` ≥ 90 (now 78–81; hydration charged to LCP under Suspense streaming). Re-measure on production first: the dev number may not hold there. If it is still under 90, fix it or record why as a decision.

### Task 12 — store WhatsApp number, slice 6 (`tasks/12-tenant-whatsapp.md`)

- [x] `deploy/systemd/belgomla-wa-gateway.service` (user `belgomla`, `Restart=on-failure`, `MemoryMax`).
- [x] `/etc/belgomla/wa-gateway.env` documented in `deploy/README.md` (template `deploy/env/wa-gateway.env.example`); the VM's Node 24 covers Node 22+.
- [x] `deploy.sh`: `npm ci && npm run build && npm prune --omit=dev` in `wa-gateway/` (`tsc` is a dev dependency), restart the unit, assert `curl 127.0.0.1:$PORT/health`.
- [x] `platform:monitor`: alert when the gateway is down or `open < connected rows` (`Messaging\Services\StoreNumberHealth`, key `wa_gateway`).
- [ ] Live check with a real number on the VM (pair → OTP from the store number → unlink leaves no ghost device).
- [ ] Mark task 12 done in its file and in `tasks/README.md` (the board row still says "planned (D70)").

### Docs

- [ ] `rules/00-session-start.md` "Production" section still matches the VM. (wa-gateway line added 2026-10-19; re-check once the VM exists.)
- [x] `02-business-rules.md` §M (tenant settings) lists `stale_pending_hours` and `invitation_message`, which exist as columns but are missing from the list.
- [x] `02-business-rules.md` BR-CU07 says "OTP is the only WhatsApp/SMS use in V1", which contradicts BR-CU08 (invitations) and BR-N01. Reword it.

## Definition of done

Every box ticked, the task files above updated with dates and commit hashes, and this file marked done on the V2 board.
