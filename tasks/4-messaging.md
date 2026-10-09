# Task 4 — Messaging module (WhatsApp + SMS for OTP and invitations)

Status: **not started**. Requires Task 0 only (independent of Catalog). Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

One `Messenger` service with a WhatsApp driver (Meta Cloud API) and an SMS driver (SMS Misr), automatic fallback, outbound message log, delivery webhooks, rate limits. Used only by Customers (Task 5) for OTP and invitations.

## Read before coding

| What      | Where                                                                                   |
| --------- | --------------------------------------------------------------------------------------- |
| Rules     | `02-business-rules.md` BR-CU07 (channel order, limits), BR-N01                          |
| Tech      | `04-technical-constraints.md` §13 (drivers, templates, queue, webhooks, logging)        |
| Tables    | `07-domain-model.md` §9 `outbound_messages`                                              |
| Endpoints | `08-api-contract.md` §13 webhooks (platform host `hooks.{base_domain}`)                 |
| Decisions | `06-open-decisions.md` D11 (SMS Misr), D12 (one platform number), D13 (platform pays)   |

## Deliverables

### 1. Schema

`outbound_messages` per §9 (`purpose`, `channel`, `provider`, `provider_message_id`, `status`, `error_code`, `fallback_of_id`, `cost`).

### 2. Module `app/Modules/Messaging`

- **Enums**: `MessagePurpose` (otp, invitation), `Channel` (whatsapp, sms), `MessageStatus` (queued, sent, delivered, failed, fell_back), `Provider` (meta_cloud, sms_misr).
- **Contracts**: `MessageDriver { send(OutboundMessage, MessagePayload): DriverResult; }` (the only interface in V1 besides `ReservationHolders`).
- **Data**: `MessagePayload` (`toPhone`, `purpose`, `template`, `variables`), `DriverResult` (`providerMessageId|null`, `ok`, `errorCode|null`, `notOnWhatsApp: bool`).
- **Services**: `Messenger::send(tenantId, MessagePayload): OutboundMessage` — creates the row, dispatches `SendMessage` job; `Fallback::shouldFallBack(DriverResult|webhook status)`; drivers `MetaWhatsAppDriver` (authentication template for OTP, utility template for invitations, `Http::withToken`), `SmsMisrDriver` (HTTP API, sender name from config); `PhoneNumber` support class in `app/Support` (E.164 normalisation for Egyptian numbers `01xxxxxxxxx` → `+201xxxxxxxxx`, validation).
- **Jobs**: `SendMessage` (tries WhatsApp, on failure/notOnWhatsApp creates the SMS row with `fallback_of_id` and dispatches itself for SMS), `ProcessWhatsAppStatus`, `ProcessSmsStatus`.
- **Actions**: `RecordDeliveryStatus` (webhook → row update; status `failed` with "not a WhatsApp user" error codes → enqueue fallback).
- **Rate limits**: `MessagingRateLimiter` service: 5 sends per phone per hour, per-IP limit configurable; exceptions `RateLimited` (429).
- **Http**: webhook controllers under `app/Modules/Messaging/Http/Controllers/Webhooks/` (`WhatsAppWebhookController@verify/@store`, `SmsMisrWebhookController@store`), signature middleware `VerifyMetaSignature` (`X-Hub-Signature-256`), `VerifySmsMisrSecret`; routes `routes/api/webhooks.php` registered on the platform host (a `hooks` host group in `routes/api.php`, outside `tenant` middleware; tenant found through `provider_message_id`).
- **Config**: `config/messaging.php` (tokens, phone number id, templates, sender name, limits) read from env; `.env.example` entries documented.
- **Dev mode**: `log` driver (writes to `storage/logs/messages.log`) selected when `MESSAGING_DRIVER=log`, so Customers tests and local dev need no provider.

### 3. Tests

- `Http::fake()` + `Http::preventStrayRequests()` for both drivers; fixtures in `tests/Fixtures/meta/*.json`, `tests/Fixtures/smsmisr/*.json` (success, not-on-whatsapp error 131026, delivery statuses).
- Unit/Services: `PhoneNumber` dataset (local, international, invalid); `Messenger` creates row + dispatches (`Queue::fake`); `Fallback` decision matrix.
- Unit/Jobs: `SendMessage` happy WhatsApp; WhatsApp fails → SMS row with `fallback_of_id`; SMS also fails → status failed, no infinite loop.
- Http/Webhooks: valid signature updates status; invalid signature 401; replayed status is idempotent; unknown provider id 404 json.
- Rate limiter: 6th send in an hour → 429.
- Arch: only `Messaging\Services` and `Messaging\Jobs` may use `Illuminate\Support\Facades\Http`; drivers implement `MessageDriver`.

### 4. Definition of done

`composer test` green; manual: with `MESSAGING_DRIVER=log` a tinker `Messenger::send` writes the log line; with real Meta sandbox credentials (if available) one OTP reaches a test phone — record the outcome in this file either way; docs; commit; mark done.

## Open questions

- D30: WhatsApp template names/languages (`otp_ar`, `invite_ar`) must be created in Meta Business Manager; who owns that account?
- D31: SMS Misr sender name text (`BelGomla`?) and NTRA registration status.
