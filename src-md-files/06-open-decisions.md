# BelGomla E-commerce — Open Decisions (Product Owner Input Required)

Each item below has a **default** already written into the other documents so work can proceed. If the product owner disagrees, the referenced rule is updated.

| #   | Decision                                                   | Default applied                                                                                               | Where       |
| --- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | ----------- |
| D5  | Basic V1 dashboard reports                                 | (a) Orders per status and total sales by date range. (b) Outstanding amount per customer. (c) Low-stock SKUs. | Roadmap §10 |
| D6  | Customer-side in-app notifications                         | None in V1. Customer sees status on the "my orders" page only.                                                | BR-N02      |
| D7  | Cancelling an order that has collections                   | Blocked until collections are voided.                                                                         | BR-O03      |
| D9  | Can a customer change the delivery address after ordering? | No. Merchant can edit the address snapshot on the order before `Out for Delivery`.                            | BR-O10      |
| D10 | Highlight threshold for stale Pending orders               | 24 hours.                                                                                                     | BR-INV07    |
| D47 | Hypersender beyond the 14-day trial                       | Starter plan for V1 (≈ $9.99/instance/month, per channel instance); re-evaluate the official Meta Cloud API at Task 11. | Tech §13 |
| D48 | Platform WhatsApp number (D12)                             | Not chosen yet. Needs a dedicated SIM on a WhatsApp account (preferably ≥ 3 months old), paired to the Hypersender instance by the platform owner, who also keeps the phone session alive. | Tech §13 |
| D49 | Android phone + SIM for SMS                                | Not chosen yet. A platform-owned Android phone with the Hypersender SMS app, charged and online 24/7 (offline after 2 h without a heartbeat); recipients see this number. Carrier SMS bundle paid by the platform (D13). | Tech §13 |
| D16 | Final revision edition modelling                           | Separate product + SKU linked to the main book via `revision_of`. Not a variant.                              | BR-P10      |
| D17 | School-book template fields                                | grade, subject, publisher, term, track, edition_type, academic_year (all select, filterable).                 | BR-P09      |
| D18 | Storefront facet order for school books                    | Grade → Subject → Publisher, then the rest.                                                                   | BR-P08      |
| D19 | Season rollover tooling in V1                              | Duplicate product + bulk status change by filter. Full import/export stays V2.                                | BR-P11/P12  |
| D21 | Pre-orders on Coming Soon products                         | V1: Coming Soon is visible but not orderable. Pre-ordering is V2.                                             | BR-P04      |
| D22 | Cover price + % pricing                                    | Added to V1 (BR-PR10, BR-PR11). Pricing engine still stores absolute prices.                                  | BR-PR10     |
| D23 | Printable price list                                       | Added to V1 as a print-friendly page. Excel/PDF export is V2.                                                 | BR-P13      |
| D24 | Credit terms (آجل) per customer                            | V2. In V1 the merchant tracks outstanding balance through collection records and the per-customer report.     | Roadmap V2  |
| D25 | Product without a category                                 | Not allowed: `category_id` is required (any level of the tree).                                               | BR-P06      |
| D26 | Gallery size                                               | At most 8 images per product, png/jpg/webp, 2 MB each. The first image becomes the main image.               | BR-P07      |
| D29 | Bulk status hits a product whose transition is not allowed | The product is skipped and counted; the response is `{ affected, skipped, batch_id }`. An empty filter is refused (422). | BR-P12 |
| D30 | Duplicate product naming                                   | Same name, new slug (`-2`, `-3`), SKU code `{code}-COPY`, `-COPY-2`, …; status `inactive`; images, relations and stock not copied. | BR-P11 |
| D31 | Products inside an archived category                       | Stay visible on the store (product status alone decides); the archived category and its subtree leave the store category tree. | BR-P06 |
| D32 | Arabic search normalisation                                | Remove tashkeel/tatweel; أ إ آ ٱ → ا, ى → ي, ة → ه; Egyptian colloquial ث → ت, ذ → د, ظ → ض; Latin lower-cased. Every query word must appear in name + SKU code + select option labels. | BR-P08 |
| D27 | Rounding of % pricing                                    | Half-up to the piaster (2 decimals), exact bcmath math: 10.10 at 25% → 7.58.                                  | BR-PR10     |
| D28 | Tier priced above the base price (surcharge)               | Not allowed: `422 invalid_tiers`. A tier equal to the base price is allowed.                                  | BR-PR05     |
| D34 | Partial pricing input                                      | `PUT …/pricing` without `cover_price` keeps it (null clears it); a percentage sent next to a price wins; a product update without `sku.tiers` keeps the tiers; bulk pricing is %-only and its tiers replace each SKU's tiers. | BR-PR10/PR11 |
| D35 | Stock adjustments to zero, unchanged values, deleting stocked SKUs | Adjusting On Hand to 0 is allowed when nothing is reserved. Setting the current value again is a no-op (no movement, no audit); in bulk it is skipped as `unchanged`. SKUs are never deleted (products are archived), so stock is never orphaned. | BR-INV08 |
| D36 | Low-stock threshold | A SKU is low on stock when Available ≤ MOQ × conversion factor, i.e. at most one minimum order left, compared in base units (BR-U03). A SKU without a stock row counts as 0. Used by the inventory list, `products?low_stock=1` and the low-stock report (D5). | BR-INV02, D5 |
| D37 | Storefront `available` / `in_stock` | `available` is in base units. `in_stock` = product `active` and Available ≥ conversion factor (at least one selling unit can be ordered). `coming_soon` → `in_stock: false`, `available` omitted. | BR-INV02, BR-P04 |
| D33 | Stationery / hardware templates                            | Filterable selects: stationery = brand, color (6 common colours), size; hardware = brand, material, size. Options other than colour start empty. | BR-P09 |
| D50 | Customer password rules                                    | At least 8 characters, at most 200, no complexity rules (`config/customers.php` `password_min`).              | BR-CU04     |
| D51 | Can a blocked customer still see the storefront?           | Yes, read-only: products, filters and the price list work (prices shown as for a logged-in customer); cart, addresses and checkout answer `403 blocked`. | BR-CU05 |
| D52 | Re-registering, and OTP requests for unknown phones        | A phone still `pending_verification` registers again with the new name/password and gets a new code. `forgot-password` and `resend-otp` give the same answer for a phone that cannot use the purpose and send nothing, so phone numbers are not revealed. | BR-CU04, BR-CU07 |
| D53 | Guest add-to-cart                                          | With `require_login_for_prices` on: `403 prices_hidden` (the storefront shows "log in to see prices"). Otherwise `401 unauthenticated` (BR-CU06: no guest carts). | BR-CU04, BR-CU06 |
| D54 | Editing a merchant-created customer's addresses            | `PUT /dashboard/customers/{id}` with `addresses[]` is the full list: rows with an `id` are edited, rows without are added, active ones missing are archived. Without `addresses` they stay. The phone cannot change. | BR-CU03, BR-CU08 |
| D55 | `Idempotency-Key` scope                                    | Per tenant host + signed-in user (guard and id) + route + key, 24 h. Only 2xx answers are stored and replayed (`Idempotent-Replayed: true`); a retry while the first runs is `409 idempotency_in_progress`; the same key with another body is `422 idempotency_key_reused`; a malformed key is `422 invalid_idempotency_key`. Applied to checkout and the dashboard order `transition` and `cancel`. | Tech §11, 08 §0 |
| D56 | Merchant places an order on behalf of a customer           | Not in V1 (V2).                                                                                              | BR-O02      |
| D57 | Checkout and order edge cases                              | Empty cart → `422 cart_empty`. An address that is not one of the customer's active addresses → `422 validation_failed` on `address_id`. Another customer's order → `404` (not 403), so sequential numbers reveal nothing. In-app notifications go to all owners (`tenant_user_id` null) and share one `read_at`. | BR-O02, BR-N02, BR-CU03 |
| D58 | Week start of the sales report                            | Saturday (the Egyptian working week), not ISO Monday. A week bucket is labelled with its Saturday; the first bucket starts on the Saturday on or before `from`. | D5, 08 §11 |
| D59 | What a customer owes (outstanding)                         | Σ (order_total − collected_amount) over **delivered** orders only. A deposit on an order not yet delivered shows on the statement (`balance`) but neither adds to nor lowers the outstanding figure. Used by the customer list/page, the statement summary, the outstanding report and the overview. | BR-C03, D24 |
| D60 | Business time zone for days                                | Africa/Cairo (`config('app.business_timezone')`). Report buckets, "today" and the `from`/`to` day filters of collections, reports and the audit log are Cairo days; timestamps stay UTC. The orders list (Task 6) still filters by UTC days until it is aligned. | D5, 08 §0 |
| D38 | Component library                                          | shadcn/ui (Radix, `radix-nova` style) with its RTL mode (`rtl: true`), which uses logical classes. Accepted in Task 8. | rules/03 §1 |
| D39 | Digits in prices and numbers                               | Western digits: `150.00 ج.م`, `1,250.50 ج.م`. Dates use Arabic month names with Western digits (`2 يوليو 2026`). Accepted in Task 8. | rules/03 §3, 01 §6 |
| D40 | UI font                                                    | IBM Plex Sans Arabic (400/500/700, Arabic + Latin subsets) via `next/font`. Accepted in Task 8. | rules/03 §1 |
| D41 | Store home when the merchant has no categories yet         | An empty state ("قريبًا") with the store's WhatsApp contact button (and phone when there is no WhatsApp) instead of the category grid. | tasks/9, 01 §7 |
| D61 | Public image URLs                                          | The `public` disk returns relative URLs (`/storage/...`, `PUBLIC_DISK_URL`), so logos and product images load from the tenant host the shopper is on. Nginx serves `/storage` (tasks/11); in dev, Next proxies it like `/api`. | Tech §8, rules/03 §4 |
| D62 | Reading an OTP in end-to-end tests                         | `GET /api/_test/last-otp?phone=` returns the newest OTP sent to that phone on the tenant. The route is registered only in the `local` and `testing` environments, never in production. | tasks/9, 08 §2 |
| D63 | Customer profile editing                                   | Not in V1: the API has no profile-update endpoint, so `/account` shows name, phone and status read-only. The merchant edits customer details from the dashboard. | BR-CU04, 08 §2 |

These are treated as accepted defaults unless the product owner objects.

## Resolved by the product owner (2026-10-09)

| Old # | Decision                  | Resolution                                                                                                           | Where                |
| ----- | ------------------------- | -------------------------------------------------------------------------------------------------------------------- | -------------------- |
| D1    | Public prices             | Public by default; tenant setting `require_login_for_prices` can hide them.                                          | BR-CU04, BR-M        |
| D2    | Customer approval         | Off by default; tenant setting `require_customer_approval` adds `Pending Approval` status.                           | BR-CU04/CU05, BR-M   |
| D3    | VAT / taxes               | Merchant prices are final; no VAT, no tax engine in V1.                                                              | BR-PR09              |
| D11   | Messaging provider        | **Superseded 2026-10-09:** Hypersender (hypersender.com) for WhatsApp and SMS behind the `MessageDriver` contract, replacing Meta Cloud API + SMS Misr. Meta Cloud remains the migration path if the unofficial channel is blocked. | Tech §13 |
| D12   | WhatsApp number           | One dedicated platform number = one Hypersender instance for all tenants in V1. Never a merchant's personal number.   | Tech §13             |
| D13   | Messaging cost            | Platform pays: Hypersender Starter after the 14-day trial (≈ $9.99/instance/month) plus carrier SMS cost on the platform SIM; usage logged per tenant. | Tech §13 |
| D46   | Unofficial WhatsApp risk  | Accepted for V1: Hypersender is not an official WhatsApp API and its docs say a block is possible. Mitigation: transactional only, ≤ 10 msgs/min, dedicated number, SMS fallback, driver swap to Meta Cloud if blocked. | Tech §13 |
| D14   | Custom field types        | text, number, select, boolean.                                                                                       | BR-P08               |
| D15   | Self-registered ordering  | Can order immediately unless `require_customer_approval` is on.                                                      | BR-CU04              |
| D20   | Returns (المرتجع)         | **Not in V1.** Planned for V2 as the first V2 increment. Schema reserves `return` movement type and `order_returns`. | BR-INV06, Roadmap V2 |
| D4    | Category depth            | Unlimited nesting; any category can have sub-categories.                                                             | BR-P06               |
| D8    | Phone verification        | OTP via WhatsApp, automatic SMS fallback if the number is not on WhatsApp.                                           | BR-CU07, Tech §13    |
| new   | Merchant creates customer | Merchant can create a customer and send an invitation link via WhatsApp/SMS.                                         | BR-CU08              |
| new   | Custom product fields     | Tenant-defined custom fields (e.g. Brand) on products.                                                               | BR-P08               |

## Already resolved during review (no input needed)

See `04-technical-constraints.md` §19.
