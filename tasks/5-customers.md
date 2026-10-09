# Task 5 — Customers module (auth, OTP, invitations, addresses, cart)

Status: **not started**. Requires Tasks 1–4. Read `CLAUDE.md`, `rules/*.md`, then this file.

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

- D32: Password rules for customers (min 8, no complexity). Default as stated.
- D33: Can a blocked customer still see the storefront? Default: yes, read-only.
