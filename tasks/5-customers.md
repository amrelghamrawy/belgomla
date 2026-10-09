# Task 5 — Customers module (auth, OTP, invitations, addresses, cart)

Status: **done** (see the delivery notes at the end). Requires Tasks 1–4. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Tenant-scoped customers with phone + password login verified by OTP (WhatsApp → SMS), merchant-created customers with invitations, approval/block, addresses, and the server-side cart with live prices and MOV check. Checkout itself is Task 6.

## Read before coding

| What      | Where                                                                                            |
| --------- | ------------------------------------------------------------------------------------------------ |
| Rules     | `02-business-rules.md` §J (BR-CU01..CU08), BR-P04 (inactive product in cart), BR-MOV02, BR-PR06 steps 1–7 (cart preview) |
| Tables    | `07-domain-model.md` §6 (customers, addresses, otps, invitations, carts, cart_items)              |
| Tech      | `04-technical-constraints.md` §6 (customer guard, session binding), §13                           |
| Endpoints | `08-api-contract.md` §2 (10 auth), §3 addresses + cart (9), §8 dashboard customers (8)             |
| Decisions | D1 (public prices + `require_login_for_prices`), D2 (`require_customer_approval`), D8, D15         |

## Using Messaging (delivered by Task 4)

- Send with `App\Modules\Messaging\Services\Messenger::send(new MessagePayload($phone, MessagePurpose::Otp|Invitation, $text, Channel::WhatsApp|Sms))`, inside the tenant context. There is no `tenantId` argument: the row takes the tenant from the context. `Channel::Sms` is the "send by SMS instead" button (BR-CU07 step 3). WhatsApp falls back to SMS on its own.
- `Messenger` normalises the phone with `App\Support\PhoneNumber::toE164()` (use it for `customers.phone` too) and throws `InvalidPhoneNumber` (`422 invalid_phone`). It also throws `MessagingRateLimited` (`429 rate_limited`, `details.retry_after`) on the 6th message to a phone within an hour. The OTP limits of BR-CU07 still belong to `SendOtp`; Messenger's limit is a backstop.
- Tests: `Queue::fake()` and `Queue::assertPushed(SendMessage::class)`, or run with `MESSAGING_DRIVER=log` (the phpunit default) and read the line `[otp] whatsapp +201… <uuid>: <text>` in `storage/logs/messages.log`.
- Use only `Messenger`, `MessagePayload` and the Messaging enums. An arch test forbids the rest.

## Deliverables

### 1. Schema

All §6 tables; `customers` unique `(tenant_id, phone)`; `customer_otps.code_hash`; `customer_invitations.token_hash` unique; `carts` unique `(customer_id)`; `cart_items` unique `(cart_id, sku_id)`.

### 2. Module `app/Modules/Customers`

- **Enums**: `CustomerStatus` (pending_verification, invited, pending_approval, active, blocked) with `canLogin()`, `OtpPurpose` (register, password_reset).
- **Models**: `Customer` (Authenticatable, `BelongsToTenant`, phone cast through `PhoneNumber`), `CustomerAddress`, `CustomerOtp`, `CustomerInvitation`, `Cart`, `CartItem`; factories with states per status.
- **Auth**: register the `customer` guard + `customers` provider in `config/auth.php` (remove the comment placeholder); `AuditLogger` already resolves it. Session binding reuses `EnsureSessionTenant:customer`.
- **Data**: `RegistrationData`, `AddressData`, `CustomerData` (merchant-created), `CartLineData`.
- **Actions**: `RegisterCustomer` (creates pending_verification + sends OTP via `Messenger`), `SendOtp` (limits, purpose), `VerifyOtp` (single use, 5 attempts, 10 min; activates or sets pending_approval per setting; starts session), `LoginCustomer` (rate limit like dashboard), `RequestPasswordReset`, `ResetPassword`, `CreateCustomerByMerchant` (status invited), `InviteCustomer` (token, 7 days, resend count), `ActivateInvitation` (sets password, verified, active/pending_approval), `ApproveCustomer`, `BlockCustomer`, `UnblockCustomer`, `CreateAddress`, `UpdateAddress`, `ArchiveAddress`, `SetDefaultAddress`, `AddCartItem`, `UpdateCartItem`, `RemoveCartItem`, `ClearCart`.
- **Services**: `OtpCodes` (generate 6 digits, hash, verify), `InvitationTokens`, `CartPricer` (uses Pricing `PriceResolver` + Inventory availability + settings MOV to build the cart view; drops inactive products into `removed_items`).
- **Queries**: `CartQuery` (hydrated lines), `CustomerListQuery` (`?q=&status=`, `outstanding_amount` = 0 until Task 7, column exists through a Collections contract later), `CustomerDetailQuery`.
- **Policies**: `CustomerAddressPolicy` (owner only) — first policy; register in a `CustomersServiceProvider`.
- **Exceptions**: `OtpInvalid`, `OtpExpired`, `OtpAttemptsExceeded`, `RegistrationRejected` (`use_invitation` 409), `LoginRejected` (customer flavour: `pending_approval` 403, `blocked` 403), `InvitationInvalid` (404), `CartLineInvalid` (422 with reasons below_moq / insufficient_stock / product_inactive), `PricesHidden` (403 when `require_login_for_prices` and guest tries add-to-cart).
- **Http**: Requests per endpoint (phone normalised in accessors), Resources `CustomerResource` (store self vs dashboard), `AddressResource`, `CartResource` (items with resolved prices, `removed_items`, `items_total`, `mov`), `InvitationResource`; controllers under `Store/Auth/*`, `Store/AddressController`, `Store/CartController`, `Store/CartItemController`, `Dashboard/CustomerController`, `Dashboard/CustomerInviteController`, `Dashboard/CustomerApprovalController`, `Dashboard/CustomerBlockController`; routes `routes/api/store/customers.php`, `routes/api/dashboard/customers.php`.
- **Store price visibility**: `StoreProductResource` / `PriceListRowResource` hide prices when the setting is on and `$request->user('customer')` is null.

### 3. Tests

- Http/Store/Auth: full flows (register → otp → active; register with approval on → pending_approval → login 403 → approve → login ok; invited flow; password reset; resend with `channel=sms` only after 60 s using `freezeTime`), throttling 429, `Messenger` faked through the `log` driver and `Queue::fake`.
- Http/Store/Cart: add/update/remove, MOQ, stock, inactive product removal, MOV flag, guest add-to-cart with hidden prices → 403, cross-tenant SKU id → 404 (BR-T04).
- Http/Dashboard/Customers: matrix + invitation resend count + approve/block transitions (409 on invalid).
- Unit: `OtpCodes`, `InvitationTokens`, `CartPricer` datasets, every Action, enums, resources, data.
- Security sweep: every customer-authenticated store route and dashboard route added.

### 4. Definition of done

`composer test` green; curl: register → read OTP from `storage/logs/messages.log` → verify → add to cart → cart shows prices and MOV; invitation link flow; docs; commit; mark done.

## Open questions

- D50 (was "D32" here; D32/D33 are catalog decisions): Password rules for customers (min 8, no complexity). Default as stated.
- D51 (was "D33"): Can a blocked customer still see the storefront? Default: yes, read-only.
- Added while building: D52 (re-registering a pending phone; no phone enumeration), D53 (guest add-to-cart: 403 `prices_hidden` vs 401), D54 (dashboard address sync).

## Delivery notes (session 2026-10-09)

Commit: `04c5b76`.

`composer test` is green with 1982 tests (1946 passed + 36 arch tests flagged deprecated by PHP 8.5's `setAccessible`), +615 over Task 4's 1367. PHPStan max is clean and type coverage is 100%.

What differs from the plan above, and why:
- `customers` has `password` (not `password_hash`, matching `tenant_users`), `remember_token`, and `blocked_at`, `blocked_reason`, `status_before_block` so unblock can restore the status (07 §6 updated).
- OTP hashes are HMAC-SHA256 with the app key: a plain hash of 6 digits is reversed by trying them all.
- Extra classes: `CheckOtp` (shared by VerifyOtp and ResetPassword, runs outside transactions so a failed attempt is counted), `UpdateCustomerByMerchant` (needed by `PUT /dashboard/customers/{id}`), `EnsureDefaultAddress`, `RefreshCart` (the cart load deletes inactive lines, a write), `OrderableQuantity` service, `Catalog\Queries\CartSkuQuery`, `InvitationByTokenQuery`, `CustomerAddressesQuery`, `OutstandingAmountsQuery`, scope `InCartOf`, exceptions `OtpSendLimited`, `PhoneTaken`, `CustomerNotActive`, middleware `EnsureCustomerActive` (`customer.active`) and `RejectGuestsWhenPricesHidden` (`prices.visible`, before auth in the priority list), dashboard `CustomerUnblockController`.
- Contracts for later modules, bound to null implementations in `CustomersServiceProvider`: `RecentOrders` (Orders rebinds in Task 6), `OutstandingBalances` (Collections rebinds in Task 7).
- `bootstrap/app.php`: Laravel turns a policy's `denyAsNotFound()` and a CSRF mismatch into plain 404/419 HttpExceptions before the renderer runs, so 404 now always renders `not_found` and 419 `csrf_token_mismatch` (the 419 case was a Task 0 bug, found during the smoke test).
- Invitation link format: `config/customers.php` `invitation.url` (`CUSTOMER_INVITATION_URL`, default `https://{host}/invite/{token}`); the storefront page arrives in Task 9.

Manual checks (`php artisan serve`, curl with `--resolve` and the SPA origin, Redis queue):
- register → `queue:work redis --queue=messaging --once` → OTP read from `storage/logs/messages.log` → `verify-otp` 200 active → `me` 200 → add 12 cartons of a SKU with a 10+ tier → cart `unit_price 85.00`, `line_total 1020.00`, `mov {enabled: true, amount: 1500.00, satisfied: false}`.
- the alnoor session cookie replayed on `kotob.gomal.test` → 401; still 200 on alnoor.
- dashboard login → create customer (`invited`) → invite 200 → link from `messages.log` → `GET invitation/{token}` 200 (masked phone) → `POST` 200 `active` → `me` 200.
- guest `POST /cart/items`: 401 with public prices, `403 prices_hidden` with `require_login_for_prices` on (setting restored afterwards); this confirms the middleware priority on the live server.

Rules covered by tests: BR-CU01..CU08, BR-P04, BR-MOQ01, BR-PR02, BR-PR04, BR-INV01, BR-U03, BR-U05, BR-MOV01, BR-MOV02, BR-T03, BR-T04, BR-L, Tech §6, D1, D2, D15, D21, D37, D50..D54.
