# V2 Task 03 — Customer order notifications (WhatsApp / SMS)

Status: **planned**. Requires V2 Task 00 (task 12 deployed). Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

When the merchant moves an order (confirmed, out for delivery, delivered, cancelled), the customer gets a WhatsApp message from the store's number (or the platform number) in words the merchant controls. The merchant chooses which statuses notify. The V1 messaging stack is reused unchanged in shape: `Messenger` → driver choice → fallback chain.

Also delivers the `whatsapp_dropped` merchant in-app notification deferred by task 12.

Out of scope: marketing/broadcast sends (D70 forbids them on this channel), inbound replies, Telegram, email.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-N01, BR-N02, BR-CU07 (limits), BR-O01/O02 |
| Tech      | `04-technical-constraints.md` §13 (Messenger, fallback, D46/D70 limits) |
| Tables    | `07-domain-model.md` §9 `outbound_messages`, `notifications`; `tenant_settings` |
| Decisions | D6, D46, D70, D72 (merchant wording, template rules) |

## Left by V1

- `Messaging\Enums\MessagePurpose`: `otp, invitation, system_alert`. The DB CHECK is `outbound_messages_purpose_known`.
- `Messaging\Data\MessagePayload(toPhone, purpose, text, channel)` → `Messenger::send()`. The per-phone limit is 5/h per tenant+phone (`MessagingRateLimiter`, `config/messaging.php` `limits.per_phone_per_hour`). Over it, `MessagingRateLimited` (429) is thrown.
- `MessageDrivers::STORE_NUMBER_PURPOSES = [Otp, Invitation]`. `Fallback::next()`: store → platform WhatsApp → SMS.
- `app/Support/InvitationMessage` + `Tenancy\Rules\InvitationTemplate` + `InvitationCard` show the merchant-worded template pattern (placeholders, 500 chars, unknown `{…}` rejected).
- `TransitionOrder` and `CancelOrder` fire no events (rules/02 §2: call the other module explicitly).
- `notifications.type` CHECK allows `new_order, customer_cancelled`.

## Deliverables

### 1. Docs

D79, D80 into 06. BR-N01 is rewritten (order status messages are V2-live). BR-N03 is new (which statuses, the templates, the opt-out). D6 is updated. Tech §13 gets the new purpose and the separate limit bucket. 07 §9 gets the new purpose and settings.

### 2. Schema

- `outbound_messages` purpose CHECK adds `'order_status'`. `outbound_messages.order_id bigint null` (FK, for the order page message log).
- `tenant_settings.order_notify_statuses jsonb` default `["confirmed","out_for_delivery","delivered","cancelled"]`, and `order_status_messages jsonb null` (map status → template; null entry = platform default).
- `customers.order_notifications_opt_out boolean default false`.
- `notifications` type CHECK adds `'whatsapp_dropped'`.

### 3. Module classes

**Messaging**

- `MessagePurpose::OrderStatus` ("تحديث طلب"), added to `STORE_NUMBER_PURPOSES`.
- `MessagingRateLimiter`: the bucket key includes the purpose group. OTP/invitation stay at 5/h, and order_status gets its own `per_phone_per_hour` (default 10) so a busy order day never blocks an OTP (D79).
- `Fallback::next()`: for `OrderStatus`, the chain stops after platform WhatsApp, with no SMS (D80).
- `MessagePayload` gains an optional `orderId`.

**Support**

- `app/Support/OrderStatusMessage`: `DEFAULTS` per status, `PLACEHOLDERS` `{اسم_العميل}`, `{اسم_المتجر}`, `{رقم_الطلب}`, `{حالة_الطلب}`, `{الإجمالي}`, `{سبب_الإلغاء}` (cancelled only), `MAX_LENGTH` 500, `render()`, `unknownPlaceholders()`. It is shaped like `InvitationMessage`, and `Tenancy\Rules\OrderStatusTemplate` validates it.

**Orders**

- Action `NotifyCustomerOfStatus::handle(Order, OrderStatus)`: does nothing when the status is not in the tenant's list, the customer opted out, or the order is a merchant-placed order flagged "no notify". Otherwise it builds the text and calls `Messenger::send` **after commit** (`DB::afterCommit`), so a rolled-back transition sends nothing. A `MessagingRateLimited` is caught and logged, never failing the transition.
- Called explicitly at the end of `TransitionOrder` and `CancelOrder` (merchant cancels only; a customer cancelling their own order gets no message).

**Tenancy**: `UpdateSettingsRequest`/`TenantSettingsResource` carry `order_notify_statuses` and `order_status_messages`.

**Messaging → merchant notice**: `RecordConnectionEvent` on a fatal close calls `Orders\Actions\NotifyMerchants` with `NotificationType::WhatsappDropped` (`data`: phone, reason). `NotifyMerchants` is generalised to take a type + data array instead of always an order. The existing callers are adapted.

### 4. Endpoints

- `GET|PATCH /api/dashboard/settings` gain the two settings fields.
- `GET /api/dashboard/orders/{order}` adds `messages[]` (`purpose, channel, status, sent_at`) from `outbound_messages.order_id`. It is read through a Messaging query, never the model.
- `PATCH /api/store/account/preferences` `{order_notifications: bool}` (the customer opt-out).

### 5. Frontend

- Settings: card "رسائل حالة الطلب", with a checkbox per status and a template editor per status (preview with sample values, placeholder chips, the same UX as `InvitationCard`).
- Order page: a "الرسائل" timeline under the status history.
- Notifications bell: renders `whatsapp_dropped` linking to settings.
- Storefront account: the "رسائل واتساب عن طلباتي" toggle.

## Test list

- `Unit/Support/OrderStatusMessageTest`: defaults per status, placeholders filled, `{سبب_الإلغاء}` only for cancelled, unknown placeholders listed, max length.
- `Unit/Rules/OrderStatusTemplateTest`.
- `Unit/Actions/NotifyCustomerOfStatusTest`: BR-N03 sends for a listed status; skips an unlisted status / opt-out; nothing sent when the transition rolls back; rate-limited does not throw; uses the merchant wording.
- `Unit/Actions/TransitionOrderTest` / `CancelOrderTest`: calls notify (merchant cancel yes, customer cancel no).
- `Unit/Services/MessageDriversTest`: order_status uses the store number when connected.
- `Unit/Services/FallbackTest`: order_status never falls back to SMS (D80).
- `Unit/Services/MessagingRateLimiterTest`: order messages do not consume the OTP bucket (D79).
- `Unit/Actions/RecordConnectionEventTest`: a fatal close writes a `whatsapp_dropped` notification.
- `Unit/Enums/MessagingEnumsTest`, `OrdersEnumsTest`.
- `Http/Dashboard/Settings/SettingsTest` (validation of templates and statuses), `Http/Dashboard/Orders/OrdersTest` (messages), `Http/Store/Account/PreferencesTest`.
- Frontend: settings card states, bell item; e2e: confirm an order → message row visible on the order page (log driver).

## Slices

1. Docs.
2. Messaging: purpose, limits, fallback, order_id.
3. Template support, settings, `NotifyCustomerOfStatus`, wiring into the transitions.
4. `whatsapp_dropped` notification (NotifyMerchants generalised).
5. Endpoints, OpenAPI, frontend.

## Definition of done

`composer test` and frontend checks green; curl with the `log` driver: confirm → `outbound_messages` row with purpose `order_status` and order_id; a live check on the VM with a real store number (one confirmed + one delivered message); docs updated.

## Open decisions (proposed)

- **D79** Order status messages have their own per-phone limit (10/hour) separate from OTP/invitation (5/hour), so order updates never block a customer's verification code. The per-number 10/min cap of D46/D70 still applies to everything.
- **D80** Order status messages go by WhatsApp only (store number → platform number) and never fall back to SMS. SMS costs the platform per segment (D13), and the status is visible on "my orders". Revisit if merchants ask.
