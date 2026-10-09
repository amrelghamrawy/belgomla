# Task 4 — Messaging module (WhatsApp + SMS via Hypersender, for OTP and invitations)

Status: **not started**. Requires Task 0 only (independent of Catalog). Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

One `Messenger` service with a single provider in V1, **Hypersender** (hypersender.com), covering both channels: WhatsApp (an instance paired to the platform's dedicated number) and SMS (the Hypersender Android app on the platform's phone + SIM). Automatic WhatsApp → SMS fallback, outbound message log, status webhooks/polling, rate limits. Used only by Customers (Task 5) for OTP and invitations.

Provider decision: `06-open-decisions.md` D11/D12/D13/D46. The `MessageDriver` contract keeps a later move to the official Meta Cloud API a driver-only change.

## Read before coding

| What        | Where                                                                                           |
| ----------- | ----------------------------------------------------------------------------------------------- |
| Rules       | `02-business-rules.md` BR-CU07 (channel order, limits), BR-N01                                  |
| Tech        | `04-technical-constraints.md` §13 (drivers, queue, webhooks, logging)                           |
| Tables      | `07-domain-model.md` §9 `outbound_messages`                                                     |
| Endpoints   | `08-api-contract.md` §13 webhooks (platform host `hooks.{base_domain}`)                         |
| Provider    | https://docs.hypersender.com (WhatsApp v2 base `https://app.hypersender.com/api/whatsapp/v2`, OTP v2, SMS API; OpenAPI spec + Postman collection linked from the SMS intro; Laravel SDK `hypersender/hypersender-laravel` v2.1.2) |

Facts verified on 2026-10-09 (re-check when starting):

- Free plan = **14-day trial**, 1 instance, 100 messages/day, 10 requests/min, 10 messages/min. After that Starter ≈ $9.99/instance/month, 3 webhook URLs per instance.
- WhatsApp side is **unofficial** (no Meta Business API; "a working WhatsApp account preferably 3 months old"); docs say a block is possible and promotional use "WILL GET YOU BANNED". Transactional only.
- SMS side = Android app (GitHub releases, not Play Store) on a phone with a SIM; heartbeat every 15 min, offline after 2 h without one; recipient sees the phone number; carrier SMS cost applies to the SIM.
- V2 API is **asynchronous**: every send returns `queued_request_uuid` + a `queued_request_link`; the real result is polled or delivered by webhook.
- Hypersender also offers its own OTP endpoints (`/api/otp/v2/{instance}/request-code`, `/validate-code`). **Do not use them**: OTP codes are generated and verified by us (`Customers\Services\OtpCodes`) so the provider stays swappable. Use plain text sends only.

## Deliverables

### 1. Schema

`outbound_messages` per §9 (`purpose`, `channel`, `provider`, `provider_request_id`, `provider_message_id`, `status`, `error_code`, `fallback_of_id`, `cost`).

### 2. Module `app/Modules/Messaging`

- **Enums**: `MessagePurpose` (otp, invitation, system_alert), `Channel` (whatsapp, sms), `MessageStatus` (queued, sent, delivered, failed, fell_back), `Provider` (hypersender, meta_cloud reserved, log).
- **Contracts**: `MessageDriver { send(OutboundMessage, MessagePayload): DriverResult; status(OutboundMessage): DriverResult; }`.
- **Data**: `MessagePayload` (`toPhone`, `purpose`, `text`), `DriverResult` (`ok`, `providerRequestId|null`, `providerMessageId|null`, `errorCode|null`, `notOnWhatsApp: bool`, `final: bool`).
- **Services**: `Messenger::send(tenantId, MessagePayload): OutboundMessage` (creates the row, dispatches `SendMessage`); `Fallback::shouldFallBack(DriverResult)`; drivers `HypersenderWhatsAppDriver` (send-text to chat id derived from the E.164 number; polls `queued_request_link`), `HypersenderSmsDriver`, `LogDriver` (dev/tests); `PhoneNumber` support class in `app/Support` (E.164 for Egypt: `01xxxxxxxxx` → `+201xxxxxxxxx`; WhatsApp chat id format per docs); `MessagingRateLimiter` (5 sends per phone per hour, global ≤ 10 messages/min to stay inside the provider limit and the ban-risk mitigation).
- **Jobs**: `SendMessage` (WhatsApp first; on `notOnWhatsApp`/failure creates the SMS row with `fallback_of_id` and dispatches itself for SMS), `PollMessageStatus` (delayed re-check of queued requests until `final`, max 5 polls), `ProcessProviderWebhook`.
- **Actions**: `RecordDeliveryStatus` (webhook or poll result → row update; failures that mean "number not on WhatsApp" → enqueue fallback). Identify the exact error code/shape from the OpenAPI spec and put it in one place (`HypersenderErrors` support class) with a test fixture.
- **Http**: `Webhooks/HypersenderWebhookController@store` with `VerifyHypersenderWebhook` middleware (shared secret in the URL or header, whatever the provider supports; document it); routes `routes/api/webhooks.php` on the platform `hooks` host group (outside `tenant` middleware; tenant found through `provider_message_id` / `provider_request_id`).
- **Config**: `config/messaging.php` (`driver`, `hypersender.token`, `hypersender.whatsapp_instance`, `hypersender.sms_instance`, `webhook_secret`, limits); `.env.example` entries.
- **Dev mode**: `MESSAGING_DRIVER=log` writes to `storage/logs/messages.log`; Customers tests read the OTP from there.

### 3. Tests

- `Http::fake()` + `Http::preventStrayRequests()`; fixtures in `tests/Fixtures/hypersender/*.json` built from the OpenAPI spec (queued response, final success, not-on-whatsapp failure, SMS success, webhook payloads).
- Unit/Services: `PhoneNumber` dataset; `Messenger` creates row + dispatches (`Queue::fake`); `Fallback` matrix; rate limiter (6th send/hour → 429, 11th send/minute → delayed not rejected).
- Unit/Jobs: queued → poll → delivered; WhatsApp fails → SMS row with `fallback_of_id`; SMS fails → `failed`, no loop; max polls reached → `failed` with error code.
- Http/Webhooks: valid secret updates status; invalid → 401; replay idempotent; unknown id → 404 json.
- Arch: only `Messaging\Services` and `Messaging\Jobs` may use `Illuminate\Support\Facades\Http`; drivers implement `MessageDriver`; no other module references Hypersender classes.

### 4. Definition of done

`composer test` green; manual: `MESSAGING_DRIVER=log` writes the log line; with the real trial instance one OTP arrives on WhatsApp and one on SMS (record both outcomes and the measured delay here); docs; commit; mark done.

## Open questions

- D30 (updated): the dedicated WhatsApp number for the platform instance; who pairs it and keeps the phone session alive?
- D31 (updated): the Android phone + SIM for SMS (model, SIM bundle, where it lives, who charges it). Recipients will see this number as the sender.
- D47: Keep Hypersender beyond the trial (Starter plan) or evaluate Meta Cloud before release? Default: Starter for V1, revisit at Task 11.
