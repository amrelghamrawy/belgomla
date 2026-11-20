# BelGomla E-commerce — Technical Constraints & Architecture Direction

## 1. Recommended Stack

### Backend

- Laravel 12.
- PHP 8.5 (composer allows ^8.3; development, tests and production all run 8.5, D66).
- REST API.
- Sanctum for authentication.
- `openspout/openspout` 5.12.2 (pinned) for the catalog and price-list spreadsheets (XLSX/CSV, streamed, needs only ext-zip/xml); only `Catalog\Services\Spreadsheet` uses it (V2 Task 06, D83, arch test).

### Frontend

- Next.js.
- React.
- TypeScript.
- Mobile-first RTL UI.

### Database

Preferred:

- PostgreSQL.

A single shared database is recommended initially.

### Supporting Infrastructure

- Redis for cache/queues where useful (database driver for queue/cache is acceptable in local development).
- S3-compatible object storage for product images (local disk in development).
- **No Docker.** Local development runs natively on Windows (PHP 8.5, Composer, PostgreSQL, Node). Production runs on a plain Linux VM with Nginx + PHP-FPM + PostgreSQL + Redis, provisioned by `deploy/provision.sh` (runbook: `deploy/README.md`, tasks/11). Decided 2026-10-09.
- Centralized application/error logging: daily log files plus `platform:monitor` alerts to the operator (D65, D66); no Sentry in V1.

## 2. Multi-Tenant Architecture

Initial approach:

**Shared database + tenant_id on tenant-owned tables.**

Avoid database-per-tenant in V1.

Tenant resolution happens from the request host.

Example:

`alnoor.belgomla.app`

resolves:

`tenant = مكتبة النور`

The backend establishes tenant context server-side.

The client must not be trusted to choose an arbitrary tenant by sending a `tenant_id` in request payloads or headers.

## 3. Tenant Isolation

Tenant isolation is a security requirement, not only a convenience.

Every tenant-owned query must be scoped to the current tenant.

Implementation patterns:

- Tenant context resolved once per request by middleware.
- Global scopes on tenant-owned Eloquent models.
- Explicit tenant-aware repositories/services for critical paths (checkout, inventory, collections).
- Authorization policies.
- Database constraints: composite unique indexes `(tenant_id, ...)` and `tenant_id NOT NULL` on every tenant-owned table.

Important operations must validate tenant ownership before access.

## 4. Resource Access

For URLs/API requests such as:

`/products/{id}`

the application must ensure the resource belongs to the current tenant.

A valid ID from another tenant must not reveal data.

Preferred response:

`404 Not Found`

rather than exposing that the resource exists.

## 5. Host and URL Conventions

Everything for a tenant lives under its own subdomain. There is no separate API or dashboard host.

| Surface          | URL                                                 |
| ---------------- | --------------------------------------------------- |
| Storefront       | `https://{tenant}.belgomla.app/`              |
| Customer account | `https://{tenant}.belgomla.app/account/...`   |
| Dashboard        | `https://{tenant}.belgomla.app/dashboard/...` |
| API              | `https://{tenant}.belgomla.app/api/...`       |

In production Nginx sends `/api/*`, `/sanctum/*` and `/up` to PHP-FPM and serves `/storage/*` from disk, before everything else goes to Next.js (`deploy/nginx/belgomla.conf`); in development the Next.js route handlers proxy the same paths. Both keep the original `Host` header, because Laravel resolves the tenant **only** from it.

Reason: if the API lived on `api.belgomla.app`, the client would have to tell the API which tenant it wants, which violates §2.

The same Next.js app and the same Laravel app serve storefront and dashboard; the path decides which UI is shown, the host decides the tenant.

There is no V1 or V2 platform-admin UI. V3 adds one on its own host, `admin.{base_domain}` (BR-T09, D111): the same Laravel and Next.js apps, `/api/admin/*` routes that never resolve a tenant, a separate `platform_admin` guard and a session cookie scoped to that host only.

## 6. Authentication

Two separate guards:

- `customer` guard: storefront customers (phone + password, tenant-scoped, OTP-verified phone).
- `tenant_user` guard: merchant dashboard users (email + password, tenant-scoped).

Sanctum SPA cookie authentication is used. The session cookie domain must be the **tenant host** (e.g. `alnoor.belgomla.app`), never the wildcard `.belgomla.app`, so a session from one tenant is never sent to another.

Every authenticated session additionally stores `tenant_id`; a request whose resolved host tenant differs from the session tenant is rejected (401).

## 7. Domain Structure

Initial domain:

`{tenant}.belgomla.app`

Example:

`alnoor.belgomla.app`

Custom domains are not V1. A wildcard TLS certificate for `*.belgomla.app` is required.

## 8. Arabic / RTL

V1 is Arabic-first and RTL-first.

The UI should not treat RTL as an afterthought.

All primary flows should be designed for Arabic mobile use.

## 9. Currency, Money and Time

- Currency: `EGP`, single currency in V1.
- Money is stored as integer piasters (or `NUMERIC(12,2)`), never floating point.
- Timestamps stored in UTC, displayed in `Africa/Cairo`.

## 9b. Catalog Data Model Notes

- Categories: adjacency list (`parent_id`) with unlimited depth. Descendant queries use a recursive CTE or a cached materialized `path`; cycles are rejected on save.
- Custom product fields: `product_field_definitions` and `product_field_options` tables per tenant plus a JSONB `custom_fields` column on `products` (`{ "grade": "<option_id>", "subject": "<option_id>" }`), validated in the application against the definitions. A GIN index on `custom_fields` supports faceted filtering. Facet counts are computed with one grouped query per facet, cached per tenant and invalidated on product change; a school-book tenant with a few thousand products stays well within PostgreSQL on a single node.
- Vertical templates were code-defined seeders (`database/verticals/*.php`) through V2. From V3 Task 01 they are platform rows (`verticals`, `vertical_sections`, `vertical_field_templates`, D108, D110), edited from the admin dashboard without a deploy and copied into each tenant (BR-P09).
- Product relations: `product_relations (tenant_id, product_id, related_product_id, type)` with a unique constraint on the triple.

## 10. Pricing Integrity

Pricing must be calculated server-side.

The frontend must never be trusted to submit the final unit price.

At checkout, the backend recalculates:

- Quantity.
- Applicable tier.
- Unit price.
- Line total.
- Order total.

## 11. Inventory Integrity

Inventory changes are transactional.

Checkout runs in a single database transaction and locks the affected SKU inventory rows (`SELECT ... FOR UPDATE`) before validating stock and reserving, so concurrent checkouts cannot oversell.

Stock reservation, cancellation release, and delivery deduction are handled atomically together with the order status change.

## 12. Historical Data

Historical orders must be independent from future catalog changes.

Order items store the necessary snapshots.

Changing:

- Product name.
- SKU.
- Unit.
- Price.
- Quantity tiers.

must not rewrite old order history.

## 13. Messaging (OTP, Invitations and Order Status)

Email is not required for V1.

WhatsApp and SMS are required for OTP and customer invitations; V2 Task 03 adds order status messages (purpose `order_status`, BR-N03), which go by WhatsApp only. Rules:

- One `Messenger` service; providers sit behind the `MessageDriver` contract. Business code never calls a provider directly.
- Provider in V1 (decided 2026-10-09, D11/D46): **Hypersender** (hypersender.com) for both channels. WhatsApp through one Hypersender instance paired to a dedicated platform number (unofficial WhatsApp, transactional messages only, ≤ 10 messages/min). SMS through the Hypersender Android app on the platform's phone + SIM; recipients see that number. Free plan is a 14-day trial; production runs on the Starter plan. The official Meta Cloud API stays the fallback plan if the number is blocked, as a driver swap.
- Store numbers (D70, tasks/12): a tenant may link its own WhatsApp number by QR. `wa-gateway/` (Node + Baileys, systemd, 127.0.0.1 only, bearer token in, HMAC-signed webhooks out) holds one session per linked number. OTPs and invitations of a tenant with a connected number go through `WaGatewayDriver`; the chain is store number → platform number → SMS, and a store number that reports "not on WhatsApp" goes straight to SMS. The gateway answers synchronously, so any refusal or an unreachable gateway fails the message at once and the platform number takes over; only its per-number limit (10/min, `429 retry_after`) makes the job wait. Disabled by default (`WA_GATEWAY_ENABLED`).
- OTP codes are generated and verified by BelGomla (`Customers\Services\OtpCodes`), not by the provider's OTP endpoints, so the provider stays replaceable.
- Fallback logic lives in `Messenger`: try WhatsApp; on a provider result meaning the number is not on WhatsApp, or on any send failure, send via SMS. Hypersender v2 is asynchronous (every send returns a queued request id), so the result comes from polling or the status webhook.
- Sends run on the queue (Redis), with webhook endpoints on the platform `hooks` host to record delivery status.
- Every send is logged per tenant (channel, provider request/message ids, status, cost if available).
- Rate limits from BR-CU07 are enforced server-side per phone and per IP.
- Checkout and order status changes never depend on messaging.
- Implementation (Task 4): plain `Http` calls, not the `hypersender/hypersender-laravel` SDK (v2.1.2 does not wrap the v2 queued requests and has bugs). WhatsApp uses `send-text-safe` (typing simulation, D46); SMS uses the synchronous v1 API. A queued request is polled up to 5 times (5, 10, 20, 40, 60 s); with no result the message fails with `poll_timeout`, and a WhatsApp message then falls back to SMS. A provider that stays unreachable for 10 minutes fails the message with `provider_unreachable` (same fallback).
- Webhook authenticity: Hypersender sends no signature, so each instance is configured to send a fixed `Authorization` header equal to `MESSAGING_WEBHOOK_SECRET` (08-api-contract.md §13).
- The message text is stored encrypted in `outbound_messages.body` only while an SMS fallback may still need it, so queued jobs carry ids, never OTP codes.
- Order status messages (V2 Task 03): `MessagePurpose::OrderStatus` uses the store number like OTPs and invitations, but its chain stops after the platform number, never SMS (D80). `Orders\Actions\NotifyCustomerOfStatus` sends after the order transaction commits (`DB::afterCommit`) and swallows `MessagingRateLimited` with a log line, so an order change never depends on messaging. `outbound_messages.order_id` ties the message to its order (copied onto fallback copies).
- A store number that logs out on the phone (`RecordConnectionEvent` → status `logged_out`) calls the `Messaging\Contracts\ConnectionAlerts` contract; the Orders module binds it to a `whatsapp_dropped` merchant notification (BR-N02).
- Limits: 5 messages per phone per hour per tenant (`429 rate_limited`, BR-CU07); order status messages use their own bucket of 10 per phone per hour (`messaging.limits.order_status_per_phone_per_hour`, D79); 10 provider calls per minute globally, where jobs wait rather than fail (D46). Per-IP limits belong to the Customers endpoints.
- `MESSAGING_DRIVER=log` (development and tests) writes `[purpose] channel +20… uuid: text` lines to `storage/logs/messages.log`.

## 14. No Online Payment

V1 should not depend on payment gateways.

Collection is an operational record, not a payment gateway integration.

## 15. No Shipping Integration

Delivery is merchant-managed in V1.

The architecture should leave room for future shipping integrations without requiring them now.

## 16. Auditing

Important business changes should be auditable.

Audit data is append-only and is not the source of truth for the business entities themselves.

## 17. Avoid Premature Microservices

V1 should remain a modular monolith.

Do not split into Go services or microservices unless actual scale or a concrete business requirement justifies it.

The codebase should still have clear modules/bounded areas (see `05-module-scope.md`) so future extraction remains possible.

## 18. Tenant Provisioning (CLI and Platform Admin Dashboard)

Tenants are managed by the platform operator in two ways that call the same Actions (BR-T07):

- the platform admin dashboard on `admin.{base_domain}` (V3, BR-T09, D111; `/api/admin/tenants`, `08-api-contract.md` §17);
- artisan commands on the server:
  - `tenant:create {subdomain} {name} --owner-email= --owner-password= --vertical=libraries` (the vertical key comes from the `verticals` table since V3 Task 01; before it the enum `school-books|stationery|hardware|none`)
  - `tenant:suspend {subdomain}` / `tenant:activate {subdomain}`
  - `tenant:list`

Every write from either path is logged in `platform_audit_logs` (D111 e). Platform admins themselves are created only by `platform:admin-create` on the server (D111 b). In V1 and V2 the commands were the only way to create a tenant.

## 19. Resolved Decisions

The following were open and are now resolved (details in `02-business-rules.md`):

1. Product and SKU are separate entities; V1 UI creates one SKU per product (BR-P02).
2. Variants are not exposed in V1 (BR-P02).
3. Customer auth = phone + password, tenant-scoped, phone verified by OTP via WhatsApp with SMS fallback (BR-CU04, BR-CU07).
4. Merchant may cancel at any status before Delivered (BR-O02).
5. URL conventions (§5 above).
6. Stock reservation happens at order creation / Pending (BR-INV03).
7. Collection recording = collection records with derived status (BR-C02, BR-C03).
8. Roles: single `owner` role in V1 (BR-T06).
9. Categories: unlimited nesting (BR-P06).
10. Merchant-created customers with WhatsApp/SMS invitation (BR-CU08).
11. Tenant-defined custom product fields (BR-P08).

Decisions still requiring a product owner answer are listed in `06-open-decisions.md`.
