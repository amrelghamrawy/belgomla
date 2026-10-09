# Task 4 — Messaging module (WhatsApp + SMS via Hypersender, for OTP and invitations)

Status: **done**, except the live Hypersender check, which needs the trial instance (D48/D49; see the delivery notes at the end). Requires Task 0 only (independent of Catalog). Read `CLAUDE.md`, `rules/*.md`, then this file.

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

- D48 (was "D30 updated"; D30/D31 are catalog decisions): the dedicated WhatsApp number for the platform instance; who pairs it and keeps the phone session alive?
- D49: the Android phone + SIM for SMS (model, SIM bundle, where it lives, who charges it). Recipients will see this number as the sender.
- D47: Keep Hypersender beyond the trial (Starter plan) or evaluate Meta Cloud before release? Default: Starter for V1, revisit at Task 11.

## Delivery notes (session 2026-10-09)

Commit: see the task board.

`composer test` is green with 1367 tests (+207 over Task 3's 1160). PHPStan max is clean and type coverage is 100%.

Manual checks:
- `MESSAGING_DRIVER=log`: `Messenger::send` for tenant `alnoor` wrote `[otp] whatsapp +201012345678 <uuid>: رمز التحقق …` to `storage/logs/messages.log`, and the row ended `sent`, provider `log`.
- `php artisan serve` with curl on `hooks.gomal.test:8000`:
  - a valid `Authorization` secret gave `200 {received: true}`; `queue:work redis --queue=messaging --once` then ran `ProcessProviderWebhook` and the row became `delivered` with its body cleared;
  - a wrong secret gave `401 unauthenticated`;
  - an unknown message gave `404 not_found`;
  - a heartbeat gave `200`;
  - the same path on `alnoor.gomal.test` gave `404`.
- **Not done:** the live Hypersender check (one OTP on WhatsApp, one on SMS, measured delay). No trial instance exists yet: the number and phone are D48/D49. To do it, set `MESSAGING_DRIVER=hypersender` and `HYPERSENDER_*` in `.env`, set the instance webhook URLs to `https://hooks.<domain>/api/webhooks/hypersender/{whatsapp|sms}` with the `Authorization` secret, run `Messenger::send` twice (once with `Channel::Sms`), and record both outcomes and delays here.

Rules covered by tests: BR-CU07 (channel order, fallback, 5 per phone per hour), BR-N01, BR-T03, BR-T04, Tech §13, D11, D46.

Provider facts verified on 2026-10-09 from the Hypersender OpenAPI specs and SDK source (tests/Fixtures/hypersender are built from them):
- Auth is `Authorization: Bearer <instance token>`. The WhatsApp and SMS instances can have different tokens (`HYPERSENDER_TOKEN`, `HYPERSENDER_SMS_TOKEN`).
- WhatsApp v2 is asynchronous: `send-text-safe` returns `queued_request_uuid`, then `GET queued-requests/{uuid}` gives `response_status` (null means pending).
- "Not on WhatsApp" shows up as `422` with `errors.exists: false` (or `404`) inside the queued result.
- SMS is **v1** and synchronous (`/api/sms/v1/{instance}/send-message`, `to` in E.164, our `request_id`).
- Webhooks have **no signature**, only a fixed `Authorization` header set per instance.
- The SDK (`hypersender/hypersender-laravel` 2.1.2) is not used. It returns raw responses without polling and has bugs: `chat_id:` named arg in the docs vs `$chatId`, ignored `sms_base_url`, and a TypeError in its exception.

Deviations from the brief above (the rules or the provider won):
- `Messenger::send(MessagePayload)` takes no `tenantId`; the row takes it from the context. `MessagePayload` gained `channel` (default WhatsApp) for BR-CU07 step 3.
- `outbound_messages` gained:
  - `uuid`, our provider reference;
  - an encrypted `body`, because a late WhatsApp failure from a poll or webhook still needs the text for the SMS copy. It is cleared once the row is final, or once an SMS is sent;
  - a unique `fallback_of_id` with a CHECK that only an SMS is a fallback, so a racing poll and webhook can never send two SMS;
  - CHECKs on purpose, channel and status.
- `Messenger` creates the row itself: a named exception in rules/02 §2. All status changes go through `Actions\RecordDeliveryStatus`, which locks the row; a final row ignores later results.
- Extra classes:
  - `Actions\AcceptHypersenderWebhook` (keeps the controller free of branching)
  - `Queries\OutboundMessageByProviderIdQuery` (cross-tenant lookup)
  - `Services\MessageContext` (jobs run inside the message's tenant)
  - `Services\MessageDrivers` (driver per channel from `messaging.driver`)
  - `Exceptions\InvalidPhoneNumber`, `Exceptions\MessagingRateLimited`
  - `Http\Requests\HypersenderWebhookRequest`
- No `MessagingServiceProvider`: nothing needs binding.
- `HypersenderErrors` lives in `Messaging\Services\Hypersender`, not `app/Support`, so the arch rule "no other module references Hypersender classes" covers it.
- Fallback triggers: not on WhatsApp, any WhatsApp send failure, an error ack (−1), a WhatsApp poll timeout (5 polls), and `provider_unreachable` after the 10-minute retry window. An SMS failure is final.
- The global limit (10 per minute) releases the job back to the queue; the log driver skips it. Webhooks for unknown events are `200` (Hypersender retries on errors).
- Test infrastructure:
  - `phpunit.xml` sets `memory_limit=512M`, because the arch boundary file outgrew the default 128M in one paratest worker;
  - `tests/Pest.php` gained `fixture()`, `useHypersender()` and `runJob()` (runs `handle()` while `Queue::fake()` is on).
- `08-api-contract.md` §13: the Meta/SMS Misr webhooks are replaced by `POST /api/webhooks/hypersender/{whatsapp|sms}` (§15 total 102).