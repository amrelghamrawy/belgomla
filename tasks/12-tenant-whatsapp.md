# Task 12 — Store WhatsApp number (QR pairing per tenant)

Status: **in progress**. Design approved 2026-10-16 (D70). Slices 2 (`wa-gateway/`) and 3 (schema, driver, routing, fallback chain) are done. Requires Tasks 4, 5, 10. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Each tenant can link **its own WhatsApp number** from dashboard settings by scanning a QR code, so its customers receive OTPs and invitations from the store's number instead of the platform's. A tenant that has not linked a number, or whose number dropped, keeps working exactly as today: platform number (Hypersender), then SMS.

The link runs through **`wa-gateway/`**, a small Node service on the same VM that holds one [Baileys](https://github.com/WhiskeySockets/Baileys) WhatsApp Web session per linked number. It has no browser, no Docker, and no business logic: it does not know tenants, only opaque session keys.

Out of scope, each needs its own decision: bulk/marketing sends (D70 forbids them on this channel), inbound messages and chat, more than one number per tenant, Meta Cloud API (Embedded Signup + Coexistence). The last of these is the official successor once the platform owner has a verified Meta business of their own.

## Decisions this task applies

- **D70** replaces D12: tenant-owned number via QR, fallback tenant number → platform number → SMS.
- D46's risk mitigation now applies per number: transactional only, ≤ 10 msgs/min per session, SMS fallback.
- Nothing is reused from other codebases. Only the lessons below are carried over, and every line is written for this repo.

## Lessons carried in from earlier QR integrations (each one has a test or a guard)

1. **Logout before purge.** Deleting the session files without `sock.logout()` leaves a ghost "linked device" on the phone. WhatsApp allows 4 linked devices, so a 5th link kicks the oldest, which shows up as random disconnects seconds after connecting. Disconnect = logout, then purge. If the logout fails, the row is still marked `disconnected` and the UI tells the merchant to remove the device from the phone.
2. **One number, one live connection.** A partial unique index on `phone` where status is `connected` blocks the same number on two tenants or twice on one.
3. **Never restart an unpaired session.** A QR nobody scans expires after 2 minutes and the session is stopped, not retried.
4. **Fatal vs transient disconnects.** `loggedOut` (401), `forbidden` (403), `connectionReplaced` (440), `badSession` (500) and `multideviceMismatch` (411) are fatal: purge, mark `logged_out`, tell the merchant. `restartRequired` (515, which always fires right after pairing), `connectionClosed`, `connectionLost`, `timedOut` and `unavailableService` reconnect with backoff 5/15/30/60/120 s, then `error`.
5. **Both directions authenticated.** Laravel → gateway sends a bearer token. Gateway → Laravel sends HMAC-SHA256 over `timestamp.body`, rejected when older than 5 minutes. The gateway listens on `127.0.0.1` only.
6. **Laravel is the source of truth.** The gateway keeps no tenant data. `messaging:wa-reconcile` (every 5 min) starts sessions that should be live, and stops and purges ones Laravel no longer knows.
7. **Bounded resources.** `WA_GATEWAY_MAX_SESSIONS` caps live sessions; a start beyond it returns `503 capacity`.
8. **Logs that can be read.** Structured JSON (pino) with `sessionKey`, `event`, `statusCode` and `reason` as fields, never interpolated.

## Read before coding

| What    | Where                                                                                      |
| ------- | ------------------------------------------------------------------------------------------ |
| Rules   | BR-CU07 (channel order, limits), BR-CU08, BR-N01                                           |
| Tech    | `04-technical-constraints.md` §13; update it in this task                                  |
| Tables  | `07-domain-model.md` §9; add `whatsapp_connections`                                        |
| API     | `08-api-contract.md` §13 webhooks; add §dashboard "WhatsApp connection" and the gateway webhook |
| Baileys | Pin the exact version when starting (npm package `baileys`). Re-check `DisconnectReason`, `onWhatsApp`, `messages.update` statuses. |

## Design

### Gateway (`wa-gateway/`, Node 22, TypeScript, run by systemd)

The API and events as built are in `wa-gateway/README.md`. Points the backend relies on:

- `GET /sessions/:key` returns `{ state: connecting|pairing|open|closed, qr, phone }`. `404` means not live.
- `DELETE` answers `{ logged_out }`, which is false when the phone could not be told.
- The send limit is 10/min per number. A send over it is refused with `429 rate_limited` and `retry_after`, and the Laravel job releases itself for that long.
- `GET /sessions` returns `{ live, stored }` for reconcile.
- `gateway.started` triggers an immediate reconcile.
- Baileys' default initial sync stays on: turning it off breaks LID mappings (Baileys warns about it). `syncFullHistory` is off.
- Tests: vitest with a fake socket (`wa-gateway/test/`). A live smoke run against WhatsApp returned a real pairing QR (2026-10-16).

### Backend (Messaging module)

- **Table `whatsapp_connections`** (tenant-owned, one row per tenant: unique `tenant_id`):
  - `session_key` (uuid, unique; the only id the gateway sees)
  - `status`: `pairing | connected | disconnected | logged_out | error`
  - `phone` (E.164, nullable; partial unique where `connected`)
  - `connected_at`, `last_event_at`, `disconnect_reason`, timestamps
- **Enums:** `ConnectionStatus`, plus `Provider::WaGateway = 'wa_gateway'` (label "رقم المتجر").
- **Actions:**
  - `StartWhatsAppPairing`: creates or resets the row with a new key, calls the gateway, audits.
  - `DisconnectWhatsApp`: gateway DELETE, status `disconnected`, audit, reports `logged_out_on_phone`.
  - `RecordConnectionEvent`: webhook → status/phone. Duplicate phone → `error: phone_in_use` plus a gateway DELETE.
- **Services:** `WaGateway\WaGatewayClient` (Http, bearer, timeout 10 s), `WaGateway\WaGatewayDriver implements MessageDriver` (synchronous: `sent(id)` or `failed('not_on_whatsapp', notOnWhatsApp: true)` / `failed('session_not_open')`).
- **`MessageDrivers`** gets the message, not only the channel. WhatsApp + purpose `otp|invitation` + tenant connection `connected` → `WaGatewayDriver`. Otherwise today's choice. `system_alert` always uses the platform number.
- **`Fallback::next()`** replaces `shouldFallBack()`:
  - `wa_gateway` failure: `notOnWhatsApp` → SMS; anything else → platform WhatsApp copy.
  - Platform WhatsApp failure → SMS (unchanged).
  - SMS → nothing.
  - Each copy is a new row with `fallback_of_id`, and the failed row becomes `fell_back`.
- **Webhook:** `POST /api/webhooks/wa-gateway` on the `hooks` host, `VerifyWaGatewaySignature` middleware, `AcceptWaGatewayWebhook` → `ProcessProviderWebhook`-style job.
- **Dashboard:** `GET|POST|DELETE /api/dashboard/settings/whatsapp`. GET returns status, phone and `qr` (live from the gateway while `pairing`).
- **Command:** `messaging:wa-reconcile`, scheduled every 5 minutes.
- **Merchant notice:** a fatal disconnect writes a merchant notification through Orders' `NotifyMerchants` action (cross-module via action).
- **Config:** `messaging.wa_gateway.{enabled, base_url, token, webhook_secret, max_sessions}`. Disabled hides the settings section (`GET` → `enabled: false`) and the driver is never chosen.

### Frontend (`settings` page)

The section is "رقم واتساب المتجر" with these states:

- **Not linked:** a "Link" button plus a notice. Use a WhatsApp Business number, messages are sent only for verification and invitations, the link counts as one of the phone's 4 linked devices, and the link is unofficial and can be dropped by WhatsApp.
- **Pairing:** the QR, polled every 3 s, stops at 2 min with "Try again".
- **Connected:** phone, connected since, "Unlink".
- **Dropped:** reason plus "Link again".

### Deploy

- `deploy/systemd/belgomla-wa-gateway.service` (user `belgomla`, `Restart=on-failure`, `MemoryMax`)
- `/etc/belgomla/wa-gateway.env`
- `deploy.sh`: `npm ci --omit=dev && npm run build` in `wa-gateway/`, restart the unit
- Post-deploy assertion: `curl 127.0.0.1:$PORT/health`
- `platform:monitor` alerts when the gateway is down or `open < connected rows`
- Node 22 added to the VM setup in `deploy/README.md`

## Test list (stubs to write first, rules/01 §6)

Backend:

- `Unit/Services/MessageDriversTest`:
  - routes an OTP to the store number when connected
  - uses the platform number when the store is not connected / pairing / logged_out
  - uses the platform number for system alerts even when connected
  - never picks the gateway when it is disabled
- `Unit/Services/FallbackTest`:
  - a store-number failure falls back to the platform number
  - a store-number not-on-WhatsApp goes straight to SMS
  - a platform failure falls back to SMS
  - an SMS failure is final
- `Unit/Actions/RecordDeliveryStatusTest`:
  - BR-CU07 the chain store → platform → SMS creates one copy per step
  - a replayed failure creates no second copy
- `Unit/Services/WaGatewayDriverTest` (Http::fake):
  - sent with id
  - not on WhatsApp
  - session not open
  - gateway down throws (job retries)
  - sends the bearer token
- `Unit/Actions/StartWhatsAppPairingTest`:
  - creates the row with a fresh key
  - re-pairing replaces the key
  - audit row
  - gateway at capacity → `503 wa_gateway_capacity`, nothing saved
- `Unit/Actions/DisconnectWhatsAppTest`:
  - logs out then marks disconnected
  - a failed logout still disconnects and reports it
  - audit row
- `Unit/Actions/RecordConnectionEventTest`:
  - open stores phone
  - fatal close → logged_out + merchant notification
  - transient close keeps connected
  - phone already connected on another tenant → error `phone_in_use` + gateway delete
  - event for an unknown key is ignored
- `Unit/Middleware/VerifyWaGatewaySignatureTest`: valid / wrong signature / stale timestamp / missing headers / empty secret refuses all
- `Http/Webhooks/WaGatewayWebhookTest`:
  - session.open
  - message.status delivered updates the row
  - payload validation
  - unknown event 200 no-op
- `Http/Dashboard/Settings/WhatsAppTest`:
  - show not linked / pairing with qr / connected
  - store starts pairing
  - destroy unlinks
  - guest 401
  - cross-host session rejected
  - disabled → `enabled: false` and store 404
  - audit rows
- `Console/WaReconcileCommandTest`:
  - starts missing connected sessions
  - deletes orphan gateway sessions
  - gateway down fails the command
- `Unit/Models/WhatsAppConnectionTest`: to-array keys, casts, BelongsToTenant trio
- `Unit/Enums/MessagingEnumsTest`: `ConnectionStatus`, `Provider::WaGateway`
- `Arch`: only Messaging calls `WaGatewayClient`
- `SecuritySweepTest`: new routes added

Gateway: listed above. Frontend: `Settings.test.tsx` covers each state and polling stopping at 2 min. e2e covers "link" showing the QR with a stubbed gateway.

## Slices (one commit each)

1. Docs: D70, 04 §13, 07 §9, 08 (this file + decision are slice 0).
2. `wa-gateway/` with its tests.
3. Backend: schema, model, enums, client, driver, routing, fallback chain.
4. Backend: webhook, actions, dashboard endpoints, reconcile, OpenAPI.
5. Frontend settings section.
6. Deploy (systemd, env, deploy.sh, monitor), then a live check with a real number on the VM.
