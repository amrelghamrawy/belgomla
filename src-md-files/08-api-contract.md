# BelGomla E-commerce — API Contract (V1)

REST API served by Laravel under the tenant host: `https://{tenant}.gomalecommerce.app/api/...` (Tech §5). Tenant is resolved from `Host` only.

Machine-readable twin: `backend/openapi/openapi.yaml` (OpenAPI 3.1, hand-written), compiled to `backend/openapi.json` by `php artisan api:openapi`, from which the frontend generates its types (Task 8). It covers the endpoints the UI uses so far; Tasks 9 and 10 extend it. Tests keep it honest: every documented path is a registered route, its `ErrorCode` enum equals the codes the backend throws, and documented response keys match real responses.

---

## 0. Conventions

### Base paths

| Prefix               | Audience                       | Guard             |
| -------------------- | ------------------------------ | ----------------- |
| `/api/store/...`     | Storefront (public + customer) | none / `customer` |
| `/api/dashboard/...` | Merchant dashboard             | `tenant_user`     |
| `/api/webhooks/...`  | Providers (Hypersender), host `hooks.{base_domain}` | shared secret |

### Auth

Sanctum SPA cookie sessions, CSRF via `GET /sanctum/csrf-cookie`. Cookie domain = tenant host (Tech §6). A session whose `tenant_id` differs from the resolved host → `401`.

### Response envelope

Every body is produced by a `JsonResource` (rules/02-architecture.md §4). Lists use Laravel's paginated resource envelope.

```json
{ "data": { ... } }                                   // single
{ "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 8, "per_page": 24, "to": 24, "total": 180, "path": "...", "links": [...] } }  // list
```

### Error envelope

```json
{
  "error": {
    "code": "validation_failed",
    "message": "رسالة عربية صالحة للعرض مباشرة",
    "details": { "field": ["..."] }, // optional
    "items": [{ "sku_id": 12, "reason": "insufficient_stock", "available": 3 }] // optional, checkout/bulk
  }
}
```

| HTTP | code                       | When                                                      |
| ---- | -------------------------- | --------------------------------------------------------- |
| 400  | `stateful_request_required` | login/OTP/invitation POST without a SPA origin (no session) |
| 401  | `unauthenticated`          | no/invalid session, tenant mismatch                       |
| 403  | `forbidden`                | a policy denied the action, or the dashboard user's role lacks the endpoint's permission (D82; possible on every dashboard endpoint except auth and `me`) |
| 403  | `pending_approval` / `blocked` / `phone_not_verified` / `invited` / `pending_verification` | customer not `active` (the code is the status, BR-CU05) |
| 403  | `tenant_suspended`         | tenant suspended (dashboard, BR-T07)                      |
| 403  | `user_disabled`            | disabled dashboard user logging in                        |
| 404  | `not_found`                | missing **or belongs to another tenant** (BR-T04)         |
| 409  | `duplicate_name` / `duplicate_key` / `duplicate_label` / `duplicate_code` / `duplicate_relation`, `in_use`, `field_type_locked` | catalog conflicts (no generic `conflict` code) |
| 409  | `phone_taken`, `use_invitation`, `email_taken`, `subdomain_taken`, `cannot_disable_self`, `last_active_user` | account conflicts |
| 419  | `csrf_token_mismatch`      | CSRF token expired; fetch `/sanctum/csrf-cookie` and retry once |
| 422  | `validation_failed`        | field validation                                          |
| 422  | `checkout_failed`          | one or more checkout assertions failed (`items[]` filled) |
| 422  | `inventory_below_reserved` | BR-INV08 (`details.orders[]` filled)                      |
| 422  | `invalid_tiers`            | BR-PR05 / D28 (`details.tiers[]`: one message per problem) |
| 422  | `cover_price_required`     | BR-PR10: a % price on a SKU without a reference price; the message says سعر الغلاف for books, سعر القطاعي otherwise (D68) |
| 403  | `prices_hidden`            | guest add-to-cart while `require_login_for_prices` (D53)  |
| 422  | `cart_line_invalid`        | a cart line below MOQ, above stock, or of an inactive product (`items[]` filled) |
| 422  | `cart_empty`               | checkout with an empty cart (D57)                         |
| 409  | `invalid_transition`       | order status change not allowed (`details.from/to`, BR-O01/O02) |
| 409  | `has_collections`          | cancelling an order with collected money (BR-O03, `details.collected_amount`) |
| 409  | `address_locked`           | editing the delivery address from `out_for_delivery` on (D9) |
| 422  | `exceeds_balance`          | a collection above the order's remaining balance (BR-C03, `details.remaining`) |
| 409  | `order_cancelled`          | recording a collection on a cancelled order (BR-C04)      |
| 409  | `is_preorder`              | recording a collection or applying credit on a pre-order (D88) |
| 409  | `group_archived`           | assigning a customer to an archived customer group (BR-PR12, D89) |
| 409  | `promotion_locked`         | editing a promotion that started, ended or was cancelled, or cancelling an ended or cancelled one (BR-PR15, D90) |
| 409  | `product_not_active`       | converting pre-orders of a product that is not active yet (BR-O14, `details.status`) |
| 409  | `already_voided`           | voiding a collection twice (BR-C02, `details.voided_at`)  |
| 409  | `idempotency_in_progress`  | same `Idempotency-Key` while the first request still runs (D55) |
| 422  | `idempotency_key_reused`   | same `Idempotency-Key` with a different body (D55)        |
| 422  | `import_expired`           | importing a preview token older than 24 h, or unknown (V2 Task 06) |
| 409  | `export_not_ready`         | the signed link of a queued catalog export before its file is written (V2 Task 06) |
| 429  | `rate_limited`             | OTP / login throttling                                    |
| 503  | `store_unavailable`        | tenant suspended (storefront)                             |
| 500  | `server_error`             | unexpected failure (no internals unless `app.debug`)      |

The complete list (also `otp_*`, `invalid_credentials`, `insufficient_stock`, `invalid_phone`, `category_cycle`, `gallery_full`, …) is the `ErrorCode` enum in `backend/openapi/openapi.yaml`.

### Common rules

- All text is Arabic; dates ISO-8601 UTC; money as string `"150.00"`.
- Lists: `?page=`, `?per_page=` (max 100), `?sort=field|-field`.
- Every mutating dashboard endpoint writes an audit record (BR-L).
- `Idempotency-Key` header is honored on `POST /api/store/checkout`, on all `POST /api/dashboard/orders/{id}/...` transitions and on `POST /api/dashboard/products/import` (24 h window).

---

## 1. Storefront — Public

### `GET /api/store/tenant`

Branding and settings needed to render the store.

```json
{ "data": { "name": "مكتبة النور", "subdomain": "alnoor", "logo_url": "...", "favicon_url": "...", "colors": {"primary":"#0A5","secondary":"#FFF"},
  "phone": "+20...", "whatsapp": "+20...", "address": "...", "description": "...",
  "working_hours": [{ "day": 0, "from": "09:00", "to": "17:00" }],   // day 0 = Sunday; [] when not set
  "currency": "EGP", "require_login_for_prices": false, "require_customer_approval": false,
  "mov": { "enabled": true, "amount": "1000.00" } } }
```

Suspended tenant → `503 store_unavailable`.

### `GET /api/store/categories`

Full active tree: `[{ id, name, slug, kind, icon, image_url, children: [...] }]`. `icon` is null unless the merchant picked one (D73).

### `GET /api/store/filters`

Facet definitions in `filter_order`, with options and counts for the **current selection**. Inside a `category`, only the fields of its section and the section-less ones are facets (D69); without a category every filterable field is.

Query: same filter params as the product list (`category`, `f[grade]=12`, `f[subject]=40`, `q`).

```json
{
  "data": [
    {
      "key": "grade",
      "label": "السنة الدراسية",
      "type": "select",
      "options": [{ "id": 12, "label": "أولى إعدادي", "count": 48 }]
    }
  ]
}
```

### `GET /api/store/products`

Query: `category` (slug, includes descendants), `f[<key>]=<option_id|true|false>` (AND), `q` (search, BR-P08), `sort=name|-created_at|price|-price`, `page`, `per_page`.

Item shape:

```json
{
  "id": 101,
  "name": "الأضواء علوم أولى إعدادي ترم أول",
  "slug": "...",
  "status": "active",
  "main_image_url": "...",
  "category": { "id": 7, "name": "إعدادي" },
  "custom_fields": [
    { "key": "grade", "label": "السنة الدراسية", "value": "أولى إعدادي" }
  ],
  "sku": {
    "id": 501,
    "code": "ADW-SCI-P1-T1",
    "unit": "كرتونة",
    "conversion_factor": 20,
    "moq": 5,
    "cover_price": "120.00",
    "base_price": "90.00",
    "discount_percent": "25.00",
    "tiers": [{ "min_quantity": 10, "unit_price": "85.00" }],
    "units": [
      { "id": 811, "unit": "كرتونة", "conversion_factor": 20, "moq": 5, "cover_price": "120.00", "base_price": "90.00", "discount_percent": "25.00", "tiers": [{ "min_quantity": 10, "unit_price": "85.00" }], "is_default": true, "in_stock": true },
      { "id": 812, "unit": "قطعة", "conversion_factor": 1, "moq": 10, "cover_price": "6.00", "base_price": "4.75", "discount_percent": "20.83", "tiers": [], "is_default": false, "in_stock": true }
    ],
    "available": 140,
    "in_stock": true
  }
}
```

V2 Task 13 (BR-U02, D91): `sku.units` lists every active unit the SKU is sold in, the default first, each priced on its own (the shopper's own prices, BR-PR14/PR15) with its own `in_stock` (Available ≥ its factor); the flat `unit`, `conversion_factor`, `moq` and prices of `sku` are the default unit's, and `sku.in_stock` is true when any unit can be ordered.

V2 Task 14 (BR-P14, D92): the product (list, page, related) also carries `skus` (every active variant, each shaped like `sku` plus `label` «أزرق / M» and `option_value_ids` in option order), `options: [{ id, name, values: [{ id, value, image_url }] }]` (only values an active variant uses; `image_url` = the swatch) and `price_range: { min, max }` (default units at the shopper's prices; left out when prices are hidden). `sku` is the first active variant in stock, else the first active one; `sku.label` / `sku.option_value_ids` are null / empty without options. Facets add `type: "option"` entries keyed `o:<folded option name>` whose option `id` is the folded value, sent back as `f[o:<name>]=<value>`. Price-list rows carry `sku_id` and `variant` (one row per unit of each active variant); cart lines carry `variant`; order items' `sku_name` is the variant label.

If `require_login_for_prices` is on and no customer session: `sku.cover_price/base_price/tiers/discount_percent` are omitted and `prices_hidden: true` is added at the product level. `coming_soon` products return `in_stock:false, coming_soon:true` and `available` omitted.

Delivered in stages (Task 1 = Catalog): the item also carries `description`; `custom_fields` lists only active fields with `show_on_storefront`, select values as the option label and booleans as `نعم`/`لا`. Inventory (Task 3): `available` is the SKU's stock in base units and `in_stock` is true when the product is `active` and at least one selling unit is available (`available ≥ conversion_factor`, D37). Pricing (Task 2): `tiers` are `{ min_quantity, unit_price }` lowest first; `discount_percent` is the % the base price was entered as, else the % it implies off `cover_price`, else `null` (no cover price). Filter facets with `type: boolean` use the option ids `"true"` / `"false"`.

### `GET /api/store/products/{slug}`

Product detail + `gallery: []` + `related: [{ type: "revision_of"|"companion", product: {…list shape…} }]` (BR-P10).

Detail notes (Task 1): `gallery` items are `{ id, url, is_main, sort_order }`; `related` items are `{ type, direction: "outgoing"|"incoming", product }` where `outgoing` means this product declared the relation (e.g. this is the revision of `product`). Relations to inactive products are not returned. An inactive or unknown slug is `404`.

### `GET /api/store/price-list`

Same filters as the product list; returns all matching products (no pagination, max 2000) in a compact shape for the printable page (BR-P13).

Row: `{ id, sku_unit_id, name, slug, kind, code, unit, conversion_factor, is_default, moq, cover_price, base_price, availability: "available"|"coming_soon" }`, one row per unit the product is sold in, the default first (V2 Task 13, D91); `meta: { count, truncated, prices_hidden }`. Prices follow the same visibility rule as the product list. `kind` is the product's section (D68) so the page can name the `cover_price` column.

### `GET /api/store/price-list/export` (V2 Task 06)

Same filters, `format=xlsx` (the only value, D83). An XLSX attachment `price-list-YYYY-MM-DD.xlsx`: every visible product of the selection by name, uncapped, with section, code, unit, MOQ, reference and wholesale price, availability. A guest of a store with `require_login_for_prices` gets `403 prices_hidden`. PDF is the page printed by the browser.

---

## 2. Storefront — Customer Auth (BR-CU04, CU07, CU08)

| Method | Path                                 | Body                               | Notes                                                                                                                          |
| ------ | ------------------------------------ | ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| POST   | `/api/store/auth/register`           | `name, phone, password`            | creates `pending_verification`, sends OTP; `200 { otp_sent_via: "whatsapp" }`; existing `invited` phone → `409 use_invitation` |
| POST   | `/api/store/auth/verify-otp`         | `phone, code, purpose`             | activates (or `pending_approval`); starts session                                                                              |
| POST   | `/api/store/auth/resend-otp`         | `phone, purpose, channel?` (`sms`) | 429 after 5/hour; `channel=sms` allowed after 60 s                                                                             |
| POST   | `/api/store/auth/login`              | `phone, password`                  | only `active`; `pending_approval` → `403 pending_approval`                                                                     |
| POST   | `/api/store/auth/logout`             |                                    |                                                                                                                                |
| POST   | `/api/store/auth/forgot-password`    | `phone`                            | sends OTP, purpose `password_reset`                                                                                            |
| POST   | `/api/store/auth/reset-password`     | `phone, code, password`            |                                                                                                                                |
| GET    | `/api/store/auth/invitation/{token}` |                                    | `{ name, phone_masked, expires_at }` or `404`                                                                                  |
| POST   | `/api/store/auth/invitation/{token}` | `password`                         | activates invited customer, starts session                                                                                     |
| GET    | `/api/store/me`                      |                                    | profile + status                                                                                                               |

All auth endpoints are throttled per phone and per IP.

Delivered in Task 5:

- Phones are accepted in any spelling `PhoneNumber` understands and answered in E.164. Passwords: min 8 (D50).
- `register`, `resend-otp` and `forgot-password` answer `{ data: { otp_sent_via } }`. A phone that cannot use the purpose gets the same answer and no message (D52); a `pending_verification` phone may register again (new code). Existing non-invited phone → `409 phone_taken`.
- OTP errors: `422 otp_invalid` (`details.attempts_left` on a wrong code), `422 otp_expired`, `422 otp_attempts_exceeded`; `429 rate_limited` with `details.retry_after` for the 6th code within an hour or `channel=sms` within 60 s.
- `verify-otp` with `purpose=register` answers the customer (session started, status `active` or `pending_approval`); with `purpose=password_reset` it only checks the code (attempt counted, code kept) and answers `204`. `reset-password` uses the code up and answers `204`.
- `login` errors: `422 invalid_credentials`; after the password matched, `403 pending_approval | blocked | phone_not_verified`; `429 rate_limited` after 5 failures per phone+IP per minute; `400 stateful_request_required` without a SPA origin (also on `verify-otp` and `invitation/{token}` POST).
- `invitation/{token}`: unknown, used, expired, superseded (re-sent) or no-longer-invited → `404 not_found`. `phone_masked` looks like `+20101****678`. The message links to the storefront page `https://{host}/account/invite/{token}` (`CUSTOMER_INVITATION_URL`).
- Test helper (D62): `GET /api/_test/last-otp?phone=` → `{ data: { code } }` (the newest OTP sent to that phone on this tenant) or `404`. Registered only when the app environment is `local` or `testing`; Playwright uses it to finish the register flow.
- `me`, `logout`: any logged-in customer, whatever the status. Profile: `{ id, name, phone, status, status_label, phone_verified_at, created_at }`.

---

## 3. Storefront — Customer (guard `customer`, status `active`)

### Addresses

| Method | Path                        | Notes                                                                    |
| ------ | --------------------------- | ------------------------------------------------------------------------ |
| GET    | `/api/store/addresses`      |                                                                          |
| POST   | `/api/store/addresses`      | `label, governorate, city, address_line, landmark?, phone?, is_default?` |
| PUT    | `/api/store/addresses/{id}` |                                                                          |
| DELETE | `/api/store/addresses/{id}` | archives                                                                 |

Address: `{ id, label, governorate, city, address_line, landmark, phone, is_default }`. The first address is the default; `phone` defaults to the customer phone; archiving the default hands it to the oldest remaining address. Another customer's (or an archived) address is `404` (CustomerAddressPolicy). Cart and addresses need status `active`: otherwise `403` with the status as the code (`blocked`, `pending_approval`, …).

### Cart (BR-CU06)

| Method | Path                             | Body               | Notes                                                                                                 |
| ------ | -------------------------------- | ------------------ | ----------------------------------------------------------------------------------------------------- |
| GET    | `/api/store/cart`                |                    | items with resolved prices, `removed_items[]` for now-inactive products (BR-P04), totals, `mov_check` |
| POST   | `/api/store/cart/items`          | `sku_unit_id \| sku_id, quantity` | upsert; validates integer, `≥ moq`, `≤ available`, product active                                     |
| PATCH  | `/api/store/cart/items/{sku_unit_id}` | `quantity`         |                                                                                                       |
| DELETE | `/api/store/cart/items/{sku_unit_id}` |                    |                                                                                                       |
| DELETE | `/api/store/cart`                |                    | clear                                                                                                 |

Cart response:

```json
{
  "data": {
    "items": [
      {
        "sku_id": 501,
        "product_name": "...",
        "product_slug": "...",
        "unit": "كرتونة",
        "conversion_factor": 20,
        "quantity": 12,
        "unit_price": "85.00",
        "tier_min_quantity": 10,
        "line_total": "1020.00",
        "moq": 5,
        "available": 140
      }
    ],
    "removed_items": [{ "product_name": "...", "reason": "inactive" }],
    "items_total": "1020.00",
    "mov": { "enabled": true, "amount": "1000.00", "satisfied": true }
  }
}
```

V2 Task 10 (BR-O14, D88): `POST /cart/items` and `PATCH /cart/items/{sku_id}` take `kind: order | preorder` (default `order`), `DELETE /cart/items/{sku_id}?kind=` too; a pre-order line needs a Coming Soon product with pre-orders on and the MOQ, never stock (`cart_line_invalid` reason `preorder_unavailable`). The cart adds `preorders: { items: [...the line shape + expected_at, available 0], items_total }` (indicative prices, no MOV). A pre-order line whose product stops taking pre-orders is dropped as `removed_items[].reason = preorder_closed`.

Delivered in Task 5: every cart write answers this same body. `quantity`, `moq` are in selling units, `available` in base units (D37). A line that cannot be ordered is `422 cart_line_invalid` with `items: [{ sku_id, reason: below_moq|insufficient_stock|product_inactive, moq?|available? }]` (`available` here in selling units). Unknown or another tenant's SKU, or a SKU not in the cart for PATCH/DELETE → `404`. `mov.enabled` is false when the setting is on without an amount. Guest `POST /cart/items`: `403 prices_hidden` when `require_login_for_prices` is on, else `401` (D53). Task 9 added `product_slug` (link back to the product page) and `conversion_factor` (so the storefront can cap the quantity at `available / conversion_factor`) to each line.

V2 Task 13 (D91): a cart line is a SKU **unit**: `POST` takes `sku_unit_id` (or `sku_id` alone for the SKU's default unit), `PATCH`/`DELETE` address the line by its unit id, and every line carries `sku_unit_id`; a SKU may be in the cart in two units (two lines). The stock check counts the base units the cart already holds of the SKU in its other units. `cart_line_invalid` and `checkout_failed` items carry `sku_unit_id` next to `sku_id`, `available` in the line's unit; checkout sums a SKU's lines in base units (a line that does not fit is refused per line). An archived unit is `product_inactive` and drops out of the cart on refresh.

### Checkout (BR-PR06, section 12 of the domain model)

`POST /api/store/checkout`

```json
{ "address_id": 9, "customer_note": "..." }
```

Success `201`: `{ "data": { "order": { ...order detail... } } }`.

Failure `422 checkout_failed`:

```json
{
  "error": {
    "code": "checkout_failed",
    "message": "تعذر إتمام الطلب، راجع العناصر التالية",
    "items": [
      {
        "sku_id": 501,
        "reason": "insufficient_stock",
        "requested": 12,
        "available": 7
      },
      { "sku_id": 777, "reason": "below_moq", "moq": 5 },
      { "sku_id": 802, "reason": "product_inactive" }
    ],
    "details": { "mov": { "required": "1000.00", "current": "700.00" } }
  }
}
```

### Orders

| Method | Path                                | Notes                                                           |
| ------ | ----------------------------------- | --------------------------------------------------------------- |
| GET    | `/api/store/orders`                 | list, `?status=`                                                |
| GET    | `/api/store/orders/{number}`        | detail: items, status, history, collection status               |
| POST   | `/api/store/orders/{number}/cancel` | `reason`; only `pending` else `409 invalid_transition` (BR-O02) |

Order detail shape (shared with dashboard, dashboard adds customer + collections):

```json
{ "number": 1024, "status": "confirmed", "placed_at": "...", "items_total": "1020.00", "order_total": "1020.00",
  "collection_status": "unpaid", "collected_amount": "0.00",
  "delivery": { "name": "...", "phone": "...", "address": { ... } }, "customer_note": "...",
  "items": [ { "product_name": "...", "sku_code": "...", "unit": "كرتونة", "quantity": 12, "unit_price": "85.00", "line_total": "1020.00" } ],
  "history": [ { "from": null, "to": "pending", "at": "...", "by": "customer" } ] }
```

Delivered in Task 6: the order also carries `status_label`, `cancel_reason`, `cancelled_by`, and each item `sku_id`, `sku_name`, `conversion_factor`, `cover_price`, `discount_percent`, `tier_min_quantity`. The list leaves out `items` and `history`. `{number}` is the per-tenant order number; another customer's or another tenant's order is `404` (D57). In `checkout_failed`, `requested`/`available` are selling units; an empty cart is `422 cart_empty`; an address that is not the customer's active address is `422 validation_failed` on `address_id`; a customer who is not active gets `403` with the status as code. Cancel needs `reason` (≤ 300); `has_collections` (409) when money was collected. Orders routes need status `active` (like the cart). Audit: `order.created`, `order.cancelled`.

V2 Task 02: `checkout_failed` adds `details.credit { limit, exposure, order_total }` when the order would pass the customer's credit limit (BR-C05, D78). `GET /api/store/account/statement` is described in §10.

V2 Task 10 (BR-O14, D88): `POST /api/store/checkout/preorder` `{ address_id, customer_note? }` (`idempotent`) turns the cart's pre-order lines into a pre-order and answers `201 { order }` like checkout; the order lines stay in the cart (and checkout leaves the pre-order lines). Failures: `422 checkout_failed` with `items` `preorder_unavailable`, `below_moq`, `preorder_limit { requested, remaining }` and `details.credit`; `cart_empty`; `validation_failed` on `address_id`. No MOV, no stock. Every order (store and dashboard) adds `kind` (`order` | `preorder`), `preorder_state` (`open` | `converted` | `cancelled`, null for a normal order) and `converted_at`; every line `converted_quantity`; the details `converted_from` (the pre-order number, or null) and `conversions[] { number, status, order_total, placed_at }`. `GET /api/store/orders?kind=` keeps one kind. A customer cancels an open pre-order with the usual cancel (not once converted in full: `409 invalid_transition`).

V2 Task 01: the order also carries `returned_total` and `net_total` (BR-O08), and the detail `returns[]`, the live returns without the merchant's reason (see §9 Returns).

V2 Task 03: `PATCH /api/store/account/preferences` `{ order_notifications: bool }` turns the WhatsApp order status messages on or off (BR-N03); answers the customer profile, which now carries `order_notifications`. Needs a session only. Audit: `customer.preferences_changed` (only when the value changes).

---

## 4. Dashboard — Auth

| Method | Path                         | Body              | Answer |
| ------ | ---------------------------- | ----------------- | ------ |
| POST   | `/api/dashboard/auth/login`  | `email, password` | `{ data: { id, name, email, role, status, last_login_at } }` |
| POST   | `/api/dashboard/auth/logout` |                   | `204`  |
| GET    | `/api/dashboard/me`          |                   | `{ data: { user: { id, name, email, role, role_label, status, last_login_at }, tenant: { name, subdomain, status, vertical }, permissions: [...] } }` (V2: the role's permissions, BR-T06) |

`login` errors: `422 invalid_credentials`, `403 user_disabled`, `429 rate_limited` after 5 failures per tenant+email+IP per minute, `400 stateful_request_required` without a SPA origin. Suspended tenant → `403 tenant_suspended`. The email is matched case-insensitively.

---

## 5. Dashboard — Settings (BR-M)

| Method | Path                           | Notes                                       |
| ------ | ------------------------------ | ------------------------------------------- |
| GET    | `/api/dashboard/settings`      |                                             |
| PUT    | `/api/dashboard/settings`      | all fields of `tenant_settings`; audited. `invitation_message` (≤ 500, only the D72 placeholders, null/empty = default); the dashboard response adds `invitation_message` and `invitation_message_default`. V2 Task 03: `order_notify_statuses` (list of `confirmed|preparing|out_for_delivery|delivered|cancelled`, each once) and `order_status_messages` (object status → text ≤ 500 with the BR-N03 placeholders, `{سبب_الإلغاء}` only in `cancelled`; null/empty/default text = default); the dashboard response adds both plus `order_status_message_defaults`. V2 Task 04: `pending_expiry_hours` (null/empty = off, 1–720, never below `stale_pending_hours`, compared with the stored value when only one is sent; dashboard only). V2 Task 08: `max_discount_percent_sales` (null = sales cannot discount, else > 0 and < 100; dashboard only) and `discount` in `order_notify_statuses` / `order_status_messages` (D85) |
| POST   | `/api/dashboard/settings/logo` | multipart; also `/favicon`                  |
| GET    | `/api/dashboard/settings/whatsapp` | D70 store number: `{ enabled, status, status_label, phone, connected_at, disconnect_reason, qr }`; `qr` (PNG data URL) only while `pairing`, read live from wa-gateway; a scan or an expiry seen there is recorded by this call. Poll every ~3 s while pairing. |
| POST   | `/api/dashboard/settings/whatsapp` | start linking by QR; audited (`whatsapp.pairing_started`); `409 whatsapp_already_connected`, `503 wa_gateway_unavailable` / `wa_gateway_capacity`, `404` when disabled |
| DELETE | `/api/dashboard/settings/whatsapp` | unlink (logout from the phone, then delete the session); audited (`whatsapp.unlinked`); adds `logged_out_on_phone` |
| GET    | `/api/dashboard/users`         | tenant users                                |
| POST   | `/api/dashboard/users`         | `name, email, password, role?` (owner when left out; V2 D82) |
| PATCH  | `/api/dashboard/users/{id}`    | `name`, `status`, `password`, `role`; `409 last_owner` when the last active owner would be demoted or disabled; a role change is audited `tenant_user.role_changed` |

---

## 6. Dashboard — Catalog

### Categories

| Method | Path                                     | Notes                                                |
| ------ | ---------------------------------------- | ---------------------------------------------------- |
| GET    | `/api/dashboard/categories`              | tree incl. archived (`?status=`)                     |
| POST   | `/api/dashboard/categories`              | `name, parent_id?, kind?, icon?, image?, sort_order?`; `kind` (D68) for roots only, default `general`; sent with a `parent_id` → `422` on `kind`; `icon` (D73) one of `CategoryIcon` or null |
| PUT    | `/api/dashboard/categories/{id}`         | `name, parent_id` (present, null = root), `kind?` (roots only; absent = keep), `icon?` (absent = keep, null = none, D73), `sort_order?`; move = change `parent_id` (the subtree takes the new root's kind); cycle → `422 category_cycle` |

Category shapes (store and dashboard) carry `kind`; product shapes carry `category: { id, name, kind }` (`CategoryRef`).
| POST   | `/api/dashboard/categories/{id}/archive` | allowed with products (BR-P06); products stay linked |
| POST   | `/api/dashboard/categories/{id}/restore` |                                                      |
| POST   | `/api/dashboard/categories/reorder`      | `[ {id, sort_order} ]`                               |

### Units

`GET/POST /api/dashboard/units`, `PUT /api/dashboard/units/{id}`, `POST .../archive`. Archive rejected (`409 in_use`) while any SKU uses the unit.

### Custom fields (BR-P08)

| Method | Path                                                    | Notes                                                                                                    |
| ------ | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| GET    | `/api/dashboard/fields`                                 | definitions + options                                                                                    |
| POST   | `/api/dashboard/fields`                                 | `key, label, type, kind?, is_required, show_on_storefront, is_filterable, filter_order, sort_order, options[]?`; `kind` null/absent = every section (D69) |
| PUT    | `/api/dashboard/fields/{id}`                            | `type` change rejected when values exist (`409`); `kind` absent = keep, null = every section              |
| POST   | `/api/dashboard/fields/{id}/archive`                    |                                                                                                          |
| POST   | `/api/dashboard/fields/{id}/options`                    | `label, sort_order`                                                                                      |
| PUT    | `/api/dashboard/fields/{id}/options/{optionId}`         | rename / reorder                                                                                         |
| POST   | `/api/dashboard/fields/{id}/options/{optionId}/archive` |                                                                                                          |

### Products

| Method | Path                                             | Notes                                                                          |
| ------ | ------------------------------------------------ | ------------------------------------------------------------------------------ | ----------- | --------- |
| GET    | `/api/dashboard/products`                        | filters: `q, category, status, f[key], low_stock=1, sort`                      |
| POST   | `/api/dashboard/products`                        | creates product **and** its single SKU in one call (BR-P02)                    |
| GET    | `/api/dashboard/products/{id}`                   | product + sku + tiers + inventory + relations + images                         |
| PUT    | `/api/dashboard/products/{id}`                   | product fields + sku fields (not stock)                                        |
| PATCH  | `/api/dashboard/products/{id}/status`            | `active                                                                        | coming_soon | inactive` |
| POST   | `/api/dashboard/products/{id}/duplicate`         | BR-P11; returns new product (status `inactive`, stock 0)                       |
| POST   | `/api/dashboard/products/{id}/images`            | multipart, `is_main?`                                                          |
| DELETE | `/api/dashboard/products/{id}/images/{imgId}`    |                                                                                |
| POST   | `/api/dashboard/products/{id}/images/reorder`    | `{ ids: [...] }` — every image of the product, in the new order               |
| GET    | `/api/dashboard/products/{id}/relations`         |                                                                                |
| POST   | `/api/dashboard/products/{id}/relations`         | `related_product_id, type`                                                     |
| DELETE | `/api/dashboard/products/{id}/relations/{relId}` |                                                                                |
| POST   | `/api/dashboard/products/bulk/status`            | `{ filter: {...same as list...}, status }` → `{ affected, skipped, batch_id }` (BR-P12, D29) |

Create/update body:

```json
{
  "name": "الأضواء علوم أولى إعدادي ترم أول",
  "category_id": 7,
  "description": "...",
  "custom_fields": {
    "grade": 12,
    "subject": 40,
    "publisher": 3,
    "term": 1,
    "track": 1,
    "edition_type": 1,
    "academic_year": 2
  },
  "sku": {
    "code": "ADW-SCI-P1-T1",
    "base_unit_id": 1,
    "units": [
      {
        "id": 811,
        "unit_id": 2,
        "conversion_factor": 20,
        "cover_price": "120.00",
        "base_price": "90.00",
        "base_price_percent": null,
        "moq": 5,
        "tiers": [{ "min_quantity": 10, "unit_price": "85.00" }],
        "is_default": true
      }
    ]
  },
  "opening_stock": 100
}
```

`base_price` **or** `base_price_percent` is required (BR-PR10); when percent is given, server computes the price and stores both (the percent wins if both are sent). Same for each tier (`unit_price` or `discount_percent`). On update, a body without `sku.tiers` keeps the tiers (D34). The detail response carries `sku.tiers: [{ min_quantity, unit_price, discount_percent }]`. Prices, MOQ and tiers are audited as `sku.price_changed` / `sku.moq_changed` / `sku.tiers_changed`, not inside `product.updated`. `opening_stock` is accepted only on create and writes an `opening` movement.

V2 Task 13 (BR-U02, D91): `sku.units` (1–10, each `unit_id` once, at most one `is_default`, none = the first) replaces the flat `selling_unit_id, conversion_factor, cover_price, base_price, base_price_percent, moq, tiers`; each unit's price rules are the ones above, keyed `sku.units.{i}.…` in errors (`invalid_tiers` and `cover_price_required` too). A unit sent with its `id` (or the `unit_id` of a unit the SKU had) is updated and active again, a new one created, one left out archived. The dashboard product's `sku` carries `base_unit`, `units: [{ id, unit: {id, name}, conversion_factor, cover_price, base_price, base_price_percent, moq, is_default, tiers? }]` (active units, default first, tiers on the detail) and the default unit's `selling_unit, conversion_factor, cover_price, base_price, base_price_percent, moq` for the lists; the price sort uses the default unit. Price audits (`sku.price_changed`, …) are on the unit (`sku_units`); `product.updated` lists `sku.units` (unit, factor, default).

V2 Task 14 (BR-P14, D92): a product with options sends `options: [{ id?, name, values: [{ id?, value, image_id? }] }]` (≤ 2 options, 1–20 values, names and values distinct when folded; `image_id` one of the product's images) and `skus: [{ id?, code, base_unit_id, status?: active|inactive, option_values: [value per option, in option order], units: […], opening_stock? }]` (1–400, codes distinct, combinations distinct, at least one active; `opening_stock` only for a SKU the call creates) instead of `sku`; without options `sku` stays (sending both, or `sku` with `options`, is `422`; `skus` without options holds one SKU). Errors are keyed `skus.{i}.…` and `options.{i}.…`; a variant code taken elsewhere is `409 duplicate_code` with `details.skus.{i}.code`. A SKU sent without `id` takes the place of the product's SKU with the same code, else the same combination. A SKU left out is deleted, or set `inactive` when it has history (D92 c); options and values left out are deleted. The dashboard product carries `skus` (every SKU, active and inactive, each like `sku` plus `label`, `status`, `sort_order`, `option_values`) and `options: [{ id, name, sort_order, values: [{ id, value, sort_order, image_id, image_url }] }]`; `sku` is the first active SKU. The audit of a product with variants lists `options` and `skus` (code, label, status, base unit, units) instead of the flat `sku.*` keys. Duplicating copies every variant. Inventory rows carry `variant`.

V2 Task 10 (BR-O14, D88): both bodies take an optional `preorder { enabled, expected_at? (Y-m-d), limit? (1..10,000,000 selling units) }`; left out it is off on create and kept on update. Every dashboard product carries `preorder { enabled, expected_at, limit }` (audited as the product fields `preorder_enabled`, `expected_at`, `preorder_limit`). `PATCH …/status` answers `meta.preorder_demand { quantity, customers, preorders }` (the product's open pre-orders); going active converts nothing by itself. The storefront product adds `preorder`: null unless Coming Soon, then `{ enabled, expected_at, remaining }` (`remaining` under the cap in selling units, null = no cap).

### Bundles (V2 Task 15, BR-P15, D93)

| Method | Path | Notes |
| ------ | ---- | ----- |
| POST | `/api/dashboard/bundles` | `catalog.manage`; `{ name, category_id, description?, custom_fields?, code, unit_id, moq?, components: [{ sku_unit_id, quantity }] (1–50, each unit once), pricing: { mode: fixed \| percent_off_sum, value }, status?: active \| inactive }` → `201` dashboard product |
| PUT | `/api/dashboard/bundles/{product}` | the same without `status`; a simple product is `404` |
| POST | `/api/dashboard/bundles/generate` | `{ filter: { category?, f?, q? } }` (not empty) → `201` the draft (inactive) grade set |

A component that is not a unit of an active SKU of an active simple product, or another bundle, is `422` on `components.{i}.sku_unit_id`; a code taken is `409 duplicate_code` on `code`. Every dashboard product carries `type` (`simple` \| `bundle`); a bundle's detail adds `bundle { pricing { mode, value }, components: [{ id, sku_unit_id, sku_id, product_id, product_name, sku_code, label, unit, conversion_factor, quantity, base_price, available, orderable }], components_total, availability }`. `PUT /products/{id}`, `POST /products/{id}/duplicate` and `PUT /products/{id}/units/{unit}/pricing` on a bundle are `409 is_bundle`. The storefront product carries `type`; a bundle adds `components: [{ product_name, slug, sku_code, label, unit, quantity, in_stock }]`, `availability` (bundles that can be made now; also the SKU's `available`) and, when prices are shown, `components_total` and `saving`. Cart lines and quote lines carry `is_bundle`. Order items (both surfaces) carry `is_bundle` and `parent_item_id`: a bundle line is followed by its component lines, which are out of the items total; partial delivery (`delivered_quantities`) names the bundle line in bundles; returns name component lines; edits and line discounts name the bundle line.

### Catalog import / export (V2 Task 06, D83, D95)

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET | `/api/dashboard/products/export?format=xlsx\|csv&<list filter>` | the filter's products (every status) in the import format, streamed as `products-YYYY-MM-DD.xlsx\|csv`; above `catalog.export_sync_limit` (5,000) `202 { data: { id, download_url } }` and a job writes the file |
| GET | `/api/dashboard/products/export/{uuid}?expires=&signature=` | the signed link of a queued export (24 h): the file, `409 export_not_ready` while it is written, `404` for a wrong link or a failed export |
| GET | `/api/dashboard/products/import/template?format=` | the empty file with the store's custom field columns |
| POST | `/api/dashboard/products/import/preview` | multipart `file` (.xlsx/.csv, ≤ 10 MB) → `{ token, file_name, total, create, update, skip, errors: [{ row, column, label, message }], errors_truncated }`; writes nothing; a file that cannot be read as a whole is `422` on `file` |
| POST | `/api/dashboard/products/import` | `{ token }` → `202` the run; one run per token; `Idempotency-Key` honored; `422 import_expired` after 24 h |
| GET | `/api/dashboard/imports/{id}` | the run: `{ id, file_name, status: queued\|running\|done\|failed, total, processed, created, updated, skipped, failed, errors, batch_id, started_at, finished_at, created_at }` |

All need `catalog.manage`. File format v2 (V2 Task 13, D91): sheet `products_v2`, one row per SKU unit; columns `sku_code*, product_name*, category_path*, status, description, base_unit*, unit*, unit_is_default (نعم/لا), conversion_factor*, cover_price, base_price*, base_price_percent, moq, tier_N_min, tier_N_price, on_hand, field:<key>`; the consecutive rows of one code are its units, the product columns are read from the first; `total`, `processed`, `create/update/skip` count SKUs; rules D95. File format v1 (still read): sheet `products_v1`; columns `sku_code*, product_name*, category_path*, status, description, selling_unit*, base_unit*, conversion_factor*, cover_price, base_price*, base_price_percent, moq, tier_N_min, tier_N_price (N = 1…5, more when a SKU has more tiers), on_hand, field:<key>` (one per active custom field). The XLSX carries the Arabic labels with the keys in a thin second row; CSV has the keys only. Row rules: D95. Audit: one `batch_id` per import (`catalog.import_started`, the product/SKU/stock records, `catalog.imported` or `catalog.import_failed`).

### Pricing (BR-PR11)

| Method | Path                                             | Body                                                                                         |
| ------ | ------------------------------------------------ | -------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| PUT    | `/api/dashboard/products/{id}/units/{sku_unit_id}/pricing` | `{ cover_price?, base_price                                                                  | base_price_percent, moq, tiers: [...] }` replaces the unit's tiers (V2 Task 13) |
| POST   | `/api/dashboard/pricing/bulk`                    | `{ filter: {...}, base_price_percent, tiers: [ { min_quantity, discount_percent } ], moq? }` |
| POST   | `/api/dashboard/pricing/bulk/preview`            | same body → `{ affected, skipped_no_cover_price: [...] }` without writing                    |
| POST   | `/api/dashboard/products/{id}/pricing/recompute` | recompute prices from stored percentages after a cover-price change                          |

Delivered (Task 2):

- `PUT …/pricing` and `…/recompute` return the SKU price set: `{ product_id, sku_id, code, cover_price, base_price, base_price_percent, discount_percent, moq, tiers: [{ min_quantity, unit_price, discount_percent }] }`. A missing `cover_price` keeps the current one (D34); `tiers` is required and replaces every tier (`[]` removes them). Errors: `422 invalid_tiers`, `422 cover_price_required`, `404` for another tenant's product.
- `POST /pricing/bulk` returns `{ affected, skipped_no_cover_price: [{ product_id, sku_id, code, name }], batch_id }`; `…/bulk/preview` returns the same without `batch_id` and writes nothing. Bulk tiers are `{ min_quantity, discount_percent }` only. An empty filter is `422` on `filter`.
- Audit: `sku.price_changed`, `sku.tiers_changed`, `sku.moq_changed` (per SKU, only what changed) and `pricing.bulk_applied` (one per bulk, same `batch_id`).
- V2 Task 13 (D91): pricing is per SKU unit. `PUT …/units/{sku_unit_id}/pricing` answers that unit's set with `sku_unit_id, unit, conversion_factor` (a unit of another product, or archived, is `404`); `…/recompute` recomputes every active unit and answers the list. Bulk pricing prices every active unit of the selection off its own cover price, or only the units sold in `unit_id` when given; `affected` counts units and skipped rows carry `sku_unit_id, unit`. Audit records are on `sku_units`.

### Promotions (V2 Task 12, BR-PR15, D90)

| Method | Path | Body / Notes |
| ------ | ---- | ------------ |
| GET    | `/api/dashboard/promotions` | `?status=draft\|scheduled\|active\|ended\|cancelled`, paginated, latest start first, each with `skus_count` |
| POST   | `/api/dashboard/promotions` | `{ name (≤ 100), discount_percent (> 0, < 90), starts_at, ends_at, filter: {...as bulk pricing...} \| sku_ids: [...] (≤ 1000), status?: draft\|scheduled }` → 201; `Idempotency-Key` honored |
| POST   | `/api/dashboard/promotions/preview` | `{ filter \| sku_ids, discount_percent? }` → `{ skus_count, sample: [{ sku_id, code, product_name, base_price, promotion_price }] }` (10), writes nothing |
| GET    | `/api/dashboard/promotions/{id}` | the promotion; an explicit list adds `skus: [{ sku_id, code, product_name }]` |
| PATCH  | `/api/dashboard/promotions/{id}` | any create field; a draft or a promotion that has not started only (`409 promotion_locked` with `details.status`); the SKUs are selected again |
| POST   | `/api/dashboard/promotions/{id}/cancel` | stops it at once; an ended or cancelled one → `409 promotion_locked` |

All need `pricing.manage`. A promotion: `{ id, name, discount_percent, starts_at, ends_at, status (as of now), target: filter\|skus, filter, skus_count, cancelled_at, created_at }`. Times without an offset are Cairo wall time (D60); answers are ISO-8601 UTC. Validation (`422`): both or neither target, an empty filter (BR-P12), a selection without any SKU, an unknown or foreign SKU id, an end not after the start or already past. Audit: `promotion.created`, `promotion.updated`, `promotion.cancelled`. `php artisan pricing:promotions-tick` (every minute) moves scheduled → active → ended.

Storefront (BR-PR15): the product list and page `sku`, the price-list row (and its Excel file) carry `promotion { id, name, discount_percent, ends_at }` when a live promotion sets the price (guests too, unless prices are hidden, D1); cart lines always carry `promotion` (null without one) and `price_source` may be `promotion`; `GET /api/store/products?on_offer=1` lists only the products on a live promotion. Order items snapshot `price_source = promotion` and `promotion_id`.

---

## 7. Dashboard — Inventory

| Method | Path                                         | Notes                                                            |
| ------ | -------------------------------------------- | ---------------------------------------------------------------- |
| GET    | `/api/dashboard/inventory`                   | per SKU: on_hand, reserved, available; `?low_stock=1&q=`         |
| GET    | `/api/dashboard/inventory/{skuId}/movements` | paginated                                                        |
| POST   | `/api/dashboard/inventory/{skuId}/adjust`    | `{ "new_on_hand": 50, "reason": "جرد" }`                         |
| POST   | `/api/dashboard/inventory/bulk-adjust`       | `[ { sku_id, new_on_hand, reason } ]` → `{ applied, skipped[] }` |

Adjust below reserved (BR-INV08) → `422`:

```json
{
  "error": {
    "code": "inventory_below_reserved",
    "message": "لا يمكن تقليل المخزون إلى 50 لأن 80 وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا.",
    "details": {
      "reserved": 80,
      "min_allowed": 80,
      "orders": [
        { "number": 1024, "status": "confirmed", "quantity_base": 60 },
        { "number": 1031, "status": "pending", "quantity_base": 20 }
      ]
    }
  }
}
```

The adjust form must call `GET /api/dashboard/inventory/{skuId}` first and display `min_allowed` next to the input.

Delivered (Task 3):

- `GET /api/dashboard/inventory/{skuId}` exists (the adjust form needs it) and returns one row; `adjust` returns the same row after the change.
- Row: `{ sku_id, product_id, code, name, status, on_hand, reserved, available, min_allowed, moq, conversion_factor, low_stock }`, all quantities in base units. `min_allowed` = `reserved`. `low_stock` is D36: `available ≤ moq × conversion_factor`.
- List: every SKU of the tenant (any product status), Laravel pagination envelope; `q` searches like the product list (name, SKU code, option labels, D32); `low_stock=1`; `sort=code|-code|available|-available` (default `code`).
- Movements: newest first, `{ id, type, type_label, quantity (signed), on_hand_after, reason, order_id, actor: { type, id }, created_at }`.
- Adjust: `new_on_hand` integer 0..10,000,000, `reason` required (≤ 200). Setting the current value is a no-op (D35). Audit `stock.adjusted` (old/new `on_hand`, `reason`).
- Bulk adjust: body is a bare JSON array (≤ 500 items, each `sku_id` once; validation errors are keyed `items.N.field`). Response `{ applied: <count>, skipped: [{ sku_id, reason: below_reserved|not_found|unchanged, reserved?, min_allowed? }], batch_id }`. An id of another tenant is `not_found` and untouched (BR-T04). Audit: `stock.adjusted` per applied SKU plus one `inventory.bulk_adjusted`, same `batch_id`.
- `422 insufficient_stock` (`items: [{ sku_id, reason, available }]`) is what `StockLedger::reserve` throws; Task 6 turns it into `checkout_failed`.
- Product create: `opening_stock` (integer ≥ 0, default 0) writes an `opening` movement and appears in the `product.created` audit; the dashboard product detail carries `sku.inventory: { on_hand, reserved, available }`; `GET /api/dashboard/products?low_stock=1` uses D36.

---

## 8. Dashboard — Customers

| Method | Path                                    | Notes                                                    |
| ------ | --------------------------------------- | -------------------------------------------------------- |
| GET    | `/api/dashboard/customers`              | `?q=&status=`; includes `outstanding_amount` (D59)       |
| POST   | `/api/dashboard/customers`              | `name, phone, addresses[]?` → status `invited` (BR-CU08) |
| GET    | `/api/dashboard/customers/{id}`         | profile, addresses, recent orders, outstanding           |
| PUT    | `/api/dashboard/customers/{id}`         | name, addresses                                          |
| POST   | `/api/dashboard/customers/{id}/invite`  | sends/re-sends invitation → `{ sent_via, expires_at }`   |
| POST   | `/api/dashboard/customers/{id}/approve` | `pending_approval → active`                              |
| POST   | `/api/dashboard/customers/{id}/block`   | `reason`                                                 |
| POST   | `/api/dashboard/customers/{id}/unblock` |                                                          |

V2 Task 02: create and update take `credit_limit` (≥ 0, null = no limit) and `payment_terms_days` (0–365, null = due on delivery); not sent on create = the store defaults, not sent on update = unchanged; changes are audited `customer.credit_terms_changed`; the dashboard customer shows both.

V2 Task 11 (BR-PR12..PR14, D89), all `pricing.customers`:

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET    | `/api/dashboard/customer-groups` | every group, active first, then `sort_order`, name; each with `customers_count` |
| POST   | `/api/dashboard/customer-groups` | `name` (≤ 100, unique → `409 duplicate_name`), `discount_percent` (0–90), `sort_order?` → 201 |
| PATCH  | `/api/dashboard/customer-groups/{id}` | any of the fields; `status: active` restores an archived group |
| POST   | `/api/dashboard/customer-groups/{id}/archive` | members keep the group, the discount stops |
| POST   | `/api/dashboard/customer-groups/{id}/assign` | `customer_ids[]` (≤ 500) or `filter {q, status, group}` (not empty) → `{ assigned }`; archived group → `409 group_archived` |
| GET    | `/api/dashboard/customers/{id}/prices` | `?q=` product or SKU code; paginated `{ sku_id, sku_unit_id, sku_code, product_name, unit, conversion_factor, base_price, unit_price, note, updated_at }` (V2 Task 13: per unit) |
| PUT    | `/api/dashboard/customers/{id}/prices/{sku_unit_id}` | `unit_price` (≥ 0.01), `note?` (≤ 300) → 201 new, 200 replaced |
| DELETE | `/api/dashboard/customers/{id}/prices/{sku_unit_id}` | 204; no such price → 404 |

The dashboard customer adds `group` (`{ id, name, discount_percent, status }` or null); the list takes `?group=`; create and update take `customer_group_id` (null = no group; needs `pricing.customers`, an archived group → `409 group_archived`, another store's → `422`). Storefront shapes are unchanged: a logged-in customer's `sku.base_price` / `tiers` and the price list's `base_price` are their own prices (BR-PR14), with `price_source` (`group` | `customer`) only then; cart lines, quote lines and order items always carry `price_source` (`base` | `tier` | `group` | `customer`).

Delivered in Task 5: the dashboard customer adds `outstanding_amount` (filled by Collections since Task 7: delivered orders only, D59), `created_by`, `approved_by`, `blocked_at`, `blocked_reason`; the detail page adds `addresses` (active) and `recent_orders` ([] until Orders, Task 6). `PUT` takes `addresses[]` as the full list (D54). `invite` answers `200 { sent_via, expires_at, sent_count }`; only `invited` customers. `approve` only from `pending_approval`; `block` from any other status (keeps it), `unblock` restores it. Invalid transitions → `409 invalid_transition` with `details.from`. Duplicate phone → `409 phone_taken`. Audit actions: `customer.created`, `customer.updated`, `customer.invited`, `customer.approved`, `customer.blocked`, `customer.unblocked`.

---

## 9. Dashboard — Orders

| Method | Path                                              | Body / Notes                                                                                                         |
| ------ | ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | --------- | ---------------- | ---------------------------------------------------- |
| GET    | `/api/dashboard/orders`                           | `?status=&collection_status=&customer_id=&from=&to=&q=&stale=1` (`stale` = pending older than `stale_pending_hours`) |
| GET    | `/api/dashboard/orders/{number}`                  | detail + customer + collections + history                                                                            |
| POST   | `/api/dashboard/orders/{number}/transition`       | `{ "to": "confirmed                                                                                                  | preparing | out_for_delivery | delivered", "note"?, "collect_full_amount"?: true }` |
| POST   | `/api/dashboard/orders/{number}/cancel`           | `{ "reason" }`; any non-terminal; `409` if `collected_amount > 0` (BR-O03)                                           |
| PUT    | `/api/dashboard/orders/{number}/delivery-address` | allowed before `out_for_delivery` (D9); audited                                                                      |
| PUT    | `/api/dashboard/orders/{number}/discount` | V2 Task 08: manual discounts while `pending`/`confirmed` (BR-PR08, D85); `orders.discount`, `idempotent`; audited |

`transition` rules (BR-O01): target must be forward of current; `delivered` triggers inventory deduction and, with `collect_full_amount: true`, creates one collection for the remaining balance in the same transaction (BR-C04). Invalid → `409 invalid_transition` with `{ "details": { "from": "preparing", "to": "confirmed" } }`.

Delivered in Task 6: the dashboard order is the store shape plus `id`, `customer { id, name, phone }`, `confirmed_at`, `delivered_at`, `cancelled_at`, `is_stale` (pending past `stale_pending_hours`), history entries with `actor_id` and `note`, and on the detail `collections` (the collection shape of §10, newest first, voided ones included). `?from=&to=` are `Y-m-d` days of `placed_at`, both inclusive; `?q=` matches the order number (`#1024` too), customer name or phone, or recipient name; `?sort=placed_at|number|order_total` (default `-placed_at`). `delivery-address` takes `governorate, city, address_line, label?, landmark?, name?, phone?` (name/phone kept when omitted) → `409 address_locked` from `out_for_delivery` on. `collect_full_amount` is ignored for other targets (see §10 for what it records). Every write answers the dashboard order detail. Audit: `order.status_changed`, `order.cancelled`, `order.delivery_address_changed`.

V2 Task 04: every dashboard order adds `expires_at` (when a pending order cancels itself; null when it is not pending or the store has no `pending_expiry_hours`), and `cancelled_by` can be `system` (D81).

V2 Task 03: the detail adds `messages[]` — the WhatsApp messages sent about the order, oldest first: `{ purpose, purpose_label, channel, status, sent_at }` (fallback copies included). A transition to a status in `order_notify_statuses`, and a merchant cancel, send the customer a message after commit (BR-N03).

### Manual discounts (V2 Task 08, BR-PR08, D85)

`PUT /api/dashboard/orders/{number}/discount` with `{ items: [{ order_item_id, type, value }], order: { type, value, reason } | null }` is the whole discount state of the order: a line left out has none, `order: null` (or no type) removes the order discount. `type` is `amount` (EGP off per unit / off the order) or `percent` (> 0 and < 100); `value` > 0 with at most 2 decimals; a line once, ≤ 200 lines; `reason` (≤ 300) is required with an order discount. Answers the dashboard order detail.

- `409 order_locked` from `preparing` on; `422 discount_too_large` when a unit or the order would go below 0.01 (`details.list_unit_price` / `details.items_total`); `409 below_collected` when the new total is under the collected amount (`details.collected_amount`, `details.order_total`); a line of another order → `422 validation_failed` on `items.N.order_item_id`.
- A sales user: line discounts only, each ≤ `max_discount_percent_sales` % off the list price, else `403 discount_over_limit` (`details.max_percent`, null when the store set no cap). Removing a discount is always allowed.
- Every order line (store and dashboard) adds `list_unit_price` and `manual_discount` (`{ type, value }` or null); `unit_price` and `line_total` are the charged prices. Every order adds `discount` (`{ type, value, amount }` or null; the dashboard adds `reason`); `order_total = items_total − discount.amount`.
- The history gets a note «خصم»; audit `order.discount_changed` with the old and new values of the changed lines and the order totals. The customer gets the `discount` WhatsApp text only when `order_notify_statuses` holds `discount`.
- `POST /api/dashboard/orders` (below) accepts `lines[].discount { type, value }` and `discount { type, value, reason }`, applied after the order is placed at list prices (same rules, one `order.discount_changed`, no `discount` message).
- Returns: `returnable[].unit_price` and the return lines are priced at the line price less the order discount's share; the last return absorbs the rounding (D85).
- Settings: `max_discount_percent_sales` (null or > 0 and < 100, 2 decimals; dashboard only); `order_notify_statuses` accepts `discount` and `order_status_messages` a `discount` text.

### Order editing and partial delivery (V2 Task 09, BR-O12, BR-O13, D86, D87)

| Method | Path                                    | Body / Notes |
| ------ | --------------------------------------- | ------------ |
| PUT    | `/api/dashboard/orders/{number}/lines`  | `{ lines: [{ order_item_id?, sku_unit_id, quantity }], note? }` (V2 Task 13: a line is a SKU unit; an existing line keeps its unit) → the dashboard order detail + `meta.below_mov`. `orders.edit` (owner, manager, sales), `idempotent` |

- **Lines** are every line the order should have: an existing live line by `order_item_id` with its own `sku_id`, a new one without. 1..200, each SKU once, `quantity` 1..1,000,000 selling units; `note` ≤ 250 (history «تعديل الطلب: note»).
- `409 order_locked` from `preparing` on (`details.status`); `409 below_collected`; `422 checkout_failed` with `items` for the added or changed lines (stock counts what the order holds), `details.credit` when a higher total passes the credit limit; `422 discount_too_large` when an EGP order discount no longer fits; `422 validation_failed` on `lines.N.order_item_id` (not a live line of the order), `lines.N.sku_id` (changed, or not this store's). `meta.below_mov` is `{ required, current }` when the new total is under the minimum order value (allowed), else null.
- Audit `order.lines_changed` (old/new totals, the changes, the note). The customer gets the `edited` WhatsApp text when `order_notify_statuses` holds `edited` (default on, D87).
- `POST …/transition` with `to: delivered` accepts `delivered_quantities: [{ order_item_id, quantity }]` (0..ordered, each line once, a line left out went out in full; ignored for other targets). `422 validation_failed` on `delivered_quantities.N.quantity` above the ordered quantity, `delivered_quantities.N.order_item_id` not a live line, or `delivered_quantities` when nothing at all is delivered; `409 below_collected`. `order.status_changed` adds `delivered_short [{ order_item_id, ordered, delivered }]` and `order_total`.
- Every order line (store and dashboard) adds `delivered_quantity` (null until delivered) and `removed_at` (null unless removed); the details list every line, removed ones too, and `changes[]` `{ order_item_id, product_name, sku_code, change, old_quantity, new_quantity, unit_price_before, unit_price_after, at }` (the dashboard adds `actor_id`).
- `POST /api/dashboard/orders/quote` accepts `order` (an order number of the customer) to quote an edit: what the order holds counts as available and its total is not counted twice against the credit limit; another customer's or store's order → `422 validation_failed` on `order`.
- `returnable[]` adds `delivered`; `returnable = delivered − returned`.
- Settings: `order_notify_statuses` accepts `edited` and `order_status_messages` an `edited` text.

### Pre-orders (V2 Task 10, BR-O14, D88)

| Method | Path | Body / Notes |
| ------ | ---- | ------------ |
| GET    | `/api/dashboard/preorders` | `?product_id=&status=open|converted|cancelled&page=&per_page=` → the pre-orders oldest first (the conversion order), in the dashboard order shape with `items`. `orders.view` |
| GET    | `/api/dashboard/products/{id}/preorder-demand` | `{ product_id, status, preorder_enabled, expected_at, limit, remaining, quantity, customers, preorders }`: the open pre-ordered selling units not converted yet. `orders.view` |
| POST   | `/api/dashboard/products/{id}/convert-preorders` | no body → `{ converted_orders: [{ number, preorder_number, quantity, order_total }], partially, untouched }`. `orders.manage`, `idempotent` |

- Conversion: `409 product_not_active` (`details.status`) until the product is active; FIFO by `placed_at`; each pre-order gets what the locked stock allows of its lines of that product as a normal pending order placed by the user (`converted_from_id`, re-priced at today's tiers, reserved; no MOQ / MOV / credit check); the rest stays on the pre-order (`converted_quantity`, a `converted` line change); a pre-order with nothing left is closed (`converted_at`). Customers who can no longer order are untouched. History «تحويل إلى طلب #N» on the pre-order, audit `order.preorder_converted` (order number, lines, the pre-order's new total, `converted`), `order.created` adds `converted_from`. Each customer gets the `preorder_converted` WhatsApp text (whatever `order_notify_statuses` says; `order_status_messages.preorder_converted` words it).
- A pre-order cannot be moved on (`409 invalid_transition`), edited or discounted (`409 order_locked`, `details.kind = preorder`), collected or credited (`409 is_preorder`); `GET /dashboard/orders?kind=` keeps one kind; a pre-order is never `is_stale` and has no `expires_at`. The quote's `order` must be a normal order. `new_preorder` notifications (`orders.manage`) tell the merchant about a customer's pre-order.

### Merchant-placed orders (V2 Task 07, BR-O11, D84)

| Method | Path                           | Body / Notes |
| ------ | ------------------------------ | ------------ |
| POST   | `/api/dashboard/orders/quote`  | `{ customer_id, lines: [{ sku_unit_id, quantity }] }` → the quote; nothing saved. `orders.create` |
| POST   | `/api/dashboard/orders`        | `{ customer_id, address_id, lines: [{ sku_unit_id, quantity, discount? }], note?, notify_customer?, override_mov? }` → `201` the dashboard order detail. `orders.create`, `idempotent` |

- `lines` 1..200, each SKU once, `quantity` 1..1,000,000 selling units; `note` ≤ 1000 (stored as the order's `customer_note`); `notify_customer` and `override_mov` default false.
- **Quote** shape (V2 Task 13: lines are SKU units, `sku_unit_id` distinct; two units of one SKU allowed; an unknown unit is `422` on `lines.{i}.sku_unit_id`): `{ lines: [{ sku_id, sku_unit_id, product_name, sku_code, sku_name, unit, conversion_factor, quantity, moq, available, cover_price, unit_price, discount_percent, tier_min_quantity, line_total, tiers: [{ min_quantity, unit_price }], problem }], items_total, mov: { enabled, amount, satisfied }, credit }`. `available` is selling units now (not locked); `problem` is the `checkout_failed` entry without `sku_id`, or null; `credit` is `{ limit, exposure, order_total }` past the limit, else null.
- **Place**: the same failures as checkout (`422 checkout_failed` with `items`, `details.mov` unless `override_mov`, `details.credit`); `override_mov: true` without `orders.override_mov` → `403 forbidden`; blocked customer → `409 customer_blocked`, invited / pending approval → `409 customer_not_active` (both `details.status`); a customer, address or SKU not of this store → `422 validation_failed` on `customer_id`, `address_id` or `lines.N.sku_id`. No `new_order` notification; `notify_customer: true` sends the `placed` WhatsApp message after commit (D84). Audit: `order.created` with `placed_by { type, id }` and `mov_overridden { required, current }` when the MOV was waived.
- Every dashboard order adds `placed_by` (`customer` | `tenant_user`) and `placed_by_id`. `order_status_messages` in the settings accepts `placed` too (not in `order_notify_statuses`).

### Returns (V2 Task 01, BR-R01..R06)

| Method | Path                                          | Body / Notes                                                                 |
| ------ | --------------------------------------------- | ---------------------------------------------------------------------------- |
| GET    | `/api/dashboard/orders/{number}/returns`      | the order's returns oldest first (voided included) + `returnable[]` per line |
| POST   | `/api/dashboard/orders/{number}/returns`      | `{ reason, returned_at?, refund?, items: [{ order_item_id, quantity }] }`    |
| POST   | `/api/dashboard/returns/{id}/void`            | `{ reason }`                                                                 |
| GET    | `/api/dashboard/returns`                      | `?from=&to=&customer_id=&q=`, paginated, newest `returned_at` first          |

- **Return** shape: `{ id, number, order { id, number, order_total, returned_total, net_total, collected_amount, collection_status }, customer { id, name }, total, refund_amount, reason, returned_at, recorded_by, is_voided, voided_at, void_reason, voided_by, items: [{ id, order_item_id, product_name, sku_code, unit, quantity, quantity_base, unit_price, line_total }], created_at }`. `order` (the balance after the change) is on the list and the writes, not on the order's own returns list or inside the order detail; `customer` is on the list only.
- **Refund to credit** (V2 Task 02): `refund_to: "credit"` with `refund: true` keeps the over-collection as customer credit (BR-C07); the return's `refund_method` says how it went back (`cash` | `credit` | null).
- **Record**: answers `201`. Only a delivered order (`409 return_not_allowed`, `details.status`). Each line at most its returnable quantity (`422 return_exceeds_delivered`, `items: [{ order_item_id, requested, returnable }]`). `items` 1..200, each line once, `quantity ≥ 1`; `returned_at` defaults to now and cannot be in the future; `reason` ≤ 300. Priced at the line's snapshot price (D74). When the order was collected beyond its new net total: `422 refund_required` with `details.refund_amount`, unless `refund: true`, which records the cash refund. Honors `Idempotency-Key`. Audit: `order.returned` (+ `collection.refunded`).
- **Void**: answers the return. `409 already_voided`; `409 return_has_refund` while its refund is live; `422 insufficient_stock` when the goods are gone again (D76). Honors `Idempotency-Key`. Audit: `order.return_voided`.
- `returnable[]`: `{ order_item_id, product_name, sku_code, unit, unit_price, ordered, returned, returnable }` (`returned` = live returns).
- The dashboard order adds `returned_total`, `net_total` and, on the detail, `returns[]` (every return, the shape above without `order`). The store order adds `returned_total`, `net_total` and, on the detail, `returns[]` of live returns `{ number, total, refund_amount, returned_at, items: [{ product_name, sku_code, unit, quantity, unit_price, line_total }] }` (no reason).

---

## 10. Dashboard — Collections (BR-C02..C04)

| Method | Path                                         | Body                                                                        |
| ------ | -------------------------------------------- | --------------------------------------------------------------------------- |
| GET    | `/api/dashboard/collections`                 | `?from=&to=&customer_id=&order=`                                            |
| POST   | `/api/dashboard/orders/{number}/collections` | `{ amount, collected_at?, note? }`; `422 exceeds_balance` if over remaining |
| POST   | `/api/dashboard/collections/{id}/void`       | `{ reason }`                                                                |
| GET    | `/api/dashboard/customers/{id}/statement`    | V2: ledger `?from=&to=` (BR-C08)                                            |
| GET    | `/api/dashboard/customers/{id}/credits`      | V2: customer credits (BR-C07)                                               |
| POST   | `/api/dashboard/orders/{number}/apply-credit` | V2: `{ credit_id, amount }`                                                |
| POST   | `/api/dashboard/credits/{id}/void`           | V2: `{ reason }`                                                            |

Delivered in Task 7:

- **Collection** shape (list, record, void, and `collections` on the dashboard order detail): `{ id, order { id, number, order_total, net_total, collected_amount, collection_status }, customer { id, name }, kind: "payment"|"refund", kind_label, order_return_id, amount, method: "cash", method_label, collected_at, recorded_by, note, is_voided, voided_at, void_reason, voided_by, created_at }`. `order` carries the balance after the change. `customer` is only on the list, not on the order detail. `recorded_by`/`voided_by` are tenant user ids (`null` = system).
- **List**: every collection, voided ones included, newest `collected_at` first. `from`/`to` are business days (D60) of `collected_at`, both inclusive. `order` is the order number.
- **Refunds** (V2 Task 01): a `refund` record is cash paid back for a return (`order_return_id`), created only by recording the return (BR-R04). It lowers `collected_amount`; voiding it raises it again. Voiding a payment that would leave more refunded than collected is `409 void_refund_first`.
- **Record**: answers `201` with the collection. `amount` is `decimal:0,2`, `> 0`, ≤ 9 999 999 999.99. `collected_at` defaults to now and cannot be in the future. `note` is at most 300 characters. Allowed at any order status but cancelled (`409 order_cancelled`); a deposit before delivery is fine (BR-C04). More than the remaining balance (net total − collected) is `422 exceeds_balance`. The order row is locked, so two concurrent collections cannot both fit one balance. Honors `Idempotency-Key`.
- **Void**: `reason` is at most 300 characters. Answers the collection; a second void is `409 already_voided`. Voiding everything makes the order cancellable again (BR-O03). Honors `Idempotency-Key`.
- `orders.collected_amount` / `collection_status` are recomputed after every record or void (BR-C03).
- **Shortcut**: `transition` to `delivered` with `collect_full_amount: true` records one collection for whatever is left, in the same transaction. Nothing is recorded when a deposit already covered the order; if the collection fails, the delivery rolls back.
- **Statement** (V2 Task 02, BR-C08): `GET /api/dashboard/customers/{id}/statement?from=&to=&page=&per_page=` answers `{ data: [ { date, type, type_label, order_number, return_number, debit, credit, balance } ], links, meta { …page, opening_balance, closing_balance }, customer { id, name, phone }, summary { orders_count, total, collected, outstanding, credit_limit, payment_terms_days, available_credit, credit_balance, overdue } }`.
  - `type` is `order`, `return`, `payment`, `credit_applied`, `refund`, `refund_to_credit` or `credit_created` (info, 0/0). Voided rows are left out. Oldest first; `balance` runs across pages and starts from `opening_balance` (everything before `from`). `from`/`to` are Cairo days (D60).
  - `summary.outstanding` counts delivered orders only (D59); `available_credit` = `credit_limit − exposure` (D77, null without a limit); `credit_balance` = unused customer credit; `overdue` per BR-C06.
- **Customer credits** (BR-C07): `GET /api/dashboard/customers/{id}/credits` lists `{ id, customer_id, source_return_id, amount, remaining, note, created_by, created_at, is_voided, voided_at, void_reason, voided_by }`, newest first. `POST /api/dashboard/orders/{number}/apply-credit { credit_id, amount }` answers `201` with the `credit` collection (`422 exceeds_credit` with `details.remaining`, `422 exceeds_balance`, `409 credit_voided`, another customer's credit `404`). `POST /api/dashboard/credits/{id}/void { reason }` answers the credit (`409 credit_applied` with `details.applied`, `409 credit_voided`). Both writes honor `Idempotency-Key`. Audit: `customer_credit.created`, `customer_credit.applied`; a voided credit is audited through its refund's `collection.voided`.
- **Store statement**: `GET /api/store/account/statement?from=&to=` — the signed-in customer's own ledger (the same rows), `meta.opening_balance/closing_balance`, `summary { outstanding, credit_limit, available_credit, credit_balance, overdue }`. Needs a session only (a blocked customer still sees what they owe).
- Audit actions: `collection.recorded`, `collection.voided`.

---

## 11. Dashboard — Reports (D5, V2 Task 16 D94)

| Method | Path                                 | Returns                                                                                    |
| ------ | ------------------------------------ | ------------------------------------------------------------------------------------------ | ---- | -------------------------- |
| GET    | `/api/dashboard/reports/overview`    | counts per order status, today's orders, stale pending, outstanding total, low-stock count |
| GET    | `/api/dashboard/reports/sales`       | `?from=&to=&group=day                                                                      | week | month` → totals per bucket |
| GET    | `/api/dashboard/reports/outstanding` | per customer: orders, total, collected, outstanding                                        |
| GET    | `/api/dashboard/reports/low-stock`   | SKUs with `available ≤ moq` (threshold param optional)                                     |
| GET    | `/api/dashboard/reports/bundles`     | V2 Task 15: `?from=&to=` → bundles sold and component consumption                           |
| GET    | `/api/dashboard/reports/sales-analytics` | V2 Task 16: gross, discounts, returns, net per bucket; previous period; by section and customer group |
| GET    | `/api/dashboard/reports/products`    | V2 Task 16: per SKU quantities, net revenue, return rate, customers (paginated, top 20 first) |
| GET    | `/api/dashboard/reports/customers`   | V2 Task 16: per customer revenue, order rhythm, dormant flag, outstanding/overdue (paginated) |
| GET    | `/api/dashboard/reports/inventory`   | V2 Task 16: per SKU stock value, days of cover, dead stock, movements (paginated + summary) |
| GET    | `/api/dashboard/reports/collections` | V2 Task 16: collected by method per bucket vs delivered, collection rate, aging |
| GET    | `/api/dashboard/reports/{name}/export` | V2 Task 16: one of the five as XLSX/CSV (`format`) |

Delivered in Task 7. All days are business days in Africa/Cairo (D60).

- **overview**:
  - Shape: `{ orders_by_status { pending, confirmed, preparing, out_for_delivery, delivered, cancelled }, today { orders, total, returns }, stale_pending, outstanding_total, overdue_total, overdue_customers, low_stock_count }` (`overdue_*` per BR-C06).
  - `today` counts orders placed since local midnight; its total leaves cancelled orders out and takes off today's live returns (`returns`, BR-R05).
  - `outstanding_total` follows D59.
- **sales**:
  - Shape: `{ from, to, group, buckets: [ { period_start, orders, gross, returns, net } ], totals { orders, gross, returns, net } }`.
  - `gross` covers delivered orders by `delivered_at`, `returns` live returns by `returned_at`, `net` = gross − returns (BR-R05).
  - Every bucket of the range is listed, with empty ones as zero. Weeks start on Saturday (D58) and the first bucket starts on the Saturday on or before `from`.
  - Without `from`/`to`, it covers the last 30 days up to today; `group` defaults to `day`.
- **outstanding**:
  - Paginated `{ customer { id, name, phone }, orders, total, collected, outstanding, aging { current, 0_30, 31_60, 61_90, over_90 }, overdue }` rows, largest debt first. `aging` splits the outstanding by days past each order's due date (`current` = not due yet; BR-C06).
  - It covers delivered orders that are not fully collected (D59); `total` and `outstanding` are net of returns.
- **bundles** (V2 Task 15, D93):
  - Shape: `{ from, to, bundles: [ { product_id, name, sku_code, quantity, total } ], components: [ { sku_id, sku_code, product_name, quantity_base } ] }`, most first.
  - Delivered orders by `delivered_at` (Cairo days, default the last 30); bundles = delivered bundle lines, components = base units delivered inside bundles.
- **Advanced reports** (V2 Task 16, D94). Every one takes `?from&to` (Cairo days, default the last 30) and, where it applies, `group=day|week|month`, `category_id` (with its sub-categories) and `customer_group_id` (the customer's current group); a filter that does not apply is ignored. Each is cached 10 minutes per tenant, filter set and page and says when it was computed (`generated_at`). Sales are delivered orders by `delivered_at`, returns live returns by `returned_at`; bundles count once at the bundle line (D93).
  - `GET /api/dashboard/reports/sales-analytics` → `{ from, to, group, generated_at, buckets: [ { period_start, orders, gross, discounts, returns, net } ], totals { orders, gross, discounts, returns, net, average_order }, previous { from, to, totals, buckets: [ { period_start, net } ] }, by_section: [ { kind, label, orders, gross, discounts, returns, net } ], by_customer_group: [ { customer_group_id, name, … } ] }`. `gross` = list prices × what went out, `discounts` = line + order discounts, `net` = gross − discounts − returns; `previous` is the same number of days just before.
  - `GET /api/dashboard/reports/products?sort=revenue|quantity|return_rate&page&per_page` (20 a page: the first is the top 20) → paginated `{ sku_id, product_id, product_name, sku_code, sku_name, is_bundle, quantity, quantity_base, bundle_quantity_base, revenue, returns, returned_base, return_rate, customers, last_sold_at }` plus `from, to, sort, generated_at`.
  - `GET /api/dashboard/reports/customers?view=top|dormant&dormant_days=45` → paginated `{ customer, customer_group, orders, revenue, average_order, first_order_at, last_order_at, lifetime_orders, average_interval_days, days_since_last_order, dormant, outstanding, overdue }` plus `from, to, view, dormant_days, generated_at`.
  - `GET /api/dashboard/reports/inventory?dead_days=60&dead_only=0|1` → paginated `{ sku_id, product_id, product_name, sku_code, sku_name, on_hand, available, stock_value, sold_base, days_of_cover, last_sold_at, dead, movements { opening, adjustment, sale, return } }` by stock value, plus `summary { skus, on_hand, stock_value, dead_count, dead_value, sold_base, movements }`, `from, to, dead_days, generated_at`. `stock_value` is at the base selling price (D94); `days_of_cover` null = ∞.
  - `GET /api/dashboard/reports/collections` → `{ from, to, group, generated_at, buckets: [ { period_start, cash, credit, refunds, net, delivered } ], totals { cash, credit, refunds, net, delivered, collection_rate }, aging { current, 0_30, 31_60, 61_90, over_90, overdue, outstanding } }`. Refunds are negative; `delivered` is the net sales of the bucket; `collection_rate` = net ÷ delivered in %.
  - `GET /api/dashboard/reports/{sales-analytics|products|customers|inventory|collections}/export?format=xlsx|csv&<the same filters and options>` → the file (`report-<name>-<from>-<to>.<format>`), every row, Arabic headers; an unknown report is `404`.
- **low-stock**:
  - Paginated `{ sku_id, product_id, product_name, code, name, available, moq, conversion_factor, threshold }`, ordered by code.
  - It covers SKUs of products that are not inactive.
  - The threshold is fixed by D36 (`moq × conversion_factor`, base units), so there is no `threshold` param.

---

## 12. Dashboard — Notifications & Audit

| Method | Path                                | Notes                                                    |
| ------ | ----------------------------------- | -------------------------------------------------------- |
| GET    | `/api/dashboard/notifications`      | `?unread=1`                                              |
| POST   | `/api/dashboard/notifications/read` | `{ ids: [] }` or `{ all: true }`                         |
| GET    | `/api/dashboard/audit`              | `?entity_type=&entity_id=&actor_id=&from=&to=&batch_id=` |

Delivered in Task 6 (notifications): newest first, `{ id, type: new_order|customer_cancelled|whatsapp_dropped|order_expired, type_label, details: { order_number, customer_name, order_total, reason? } (whatsapp_dropped: { phone, reason }), read_at, created_at }` plus top-level `unread_count` next to `meta` (`details`, not `data`, so the item does not collide with the envelope). `read` answers `{ data: { marked } }`; ids of another tenant or already read are skipped; audited `notifications.read`. V2 Task 05: the list, `unread_count` and `read` cover only the notifications the user's role may see (D82).

Delivered in Task 7 (audit):
- The list is paginated newest first, as `{ id, actor { type, id }, action, entity { type, id }, old_values, new_values, batch_id, ip, created_at }`.
- `entity_type` is the table name (`orders`, `collections`, `products`, `sku_inventory`, …).
- `from`/`to` are business days (D60).
- `batch_id` must be a uuid and returns exactly the rows of one bulk action.
- Reading the log is not audited.

---

## 13. Webhooks (Tech §13)

| Method | Path                                  | Notes                                                                                                                       |
| ------ | ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/api/webhooks/hypersender/whatsapp`  | `message.ack` statuses (ack ≥ 2 delivered, −1 error); an error ack on a WhatsApp message → SMS fallback enqueued (BR-CU07)  |
| POST   | `/api/webhooks/hypersender/sms`       | `message.phone.sent`, `message.phone.delivered`, `message.send.failed`, `message.send.expired`                              |
| POST   | `/api/webhooks/wa-gateway`            | D70: `{ event, session_key, data, sent_at }` from wa-gateway: `gateway.started` → reconcile queued; `session.open { phone }` / `session.closed { reason, fatal }` → RecordConnectionEvent; `message.status { id, status: delivered\|read\|failed }` → store-number messages only (failed falls back). Unknown sessions or messages: `200`, dropped. |

Webhooks are platform-level (not tenant hosts): they are served only on `https://hooks.{base_domain}/...` (`hooks.gomal.test` locally) and locate the tenant through the message (`provider_message_id`, `provider_request_id`, or our `uuid` sent as the SMS `request_id`).

- **Auth:** Hypersender has no webhook signature. Each instance is configured in the Hypersender dashboard to send `Authorization: <MESSAGING_WEBHOOK_SECRET>` (`Bearer <secret>` also accepted); anything else is `401 unauthenticated`, and an empty secret refuses every call.
- **wa-gateway auth:** `X-Gateway-Signature` = hex HMAC-SHA256 of `{X-Gateway-Timestamp}.{raw body}` under `WA_GATEWAY_WEBHOOK_SECRET`; a wrong signature, an empty secret or a timestamp more than 5 minutes off is `401 unauthenticated`.
- **Body:** `{ event, data }` as Hypersender sends it; `422 validation_failed` without them.
- **Responses:** `200 { "data": { "received": true } }`; an SMS app `phone.heartbeat.received` is stored in `sms_phone_heartbeats` (our receive time) for the monitor's 2-hour offline alert (D49, tasks/11); other events that are not delivery statuses (incoming messages) are acknowledged and dropped; a status for a message we never sent is `404 not_found`. Processing is queued (`ProcessProviderWebhook`) and idempotent: a final message ignores replays.

---

## 14. Platform CLI (not HTTP, Tech §18)

```
php artisan tenant:create alnoor "مكتبة النور" --owner-email=... --owner-password=... --vertical=school-books
php artisan tenant:suspend alnoor
php artisan tenant:activate alnoor
php artisan tenant:list
php artisan api:openapi          # compile backend/openapi/openapi.yaml → backend/openapi.json (Task 8)
php artisan messaging:wa-reconcile  # D70: start connected store numbers on wa-gateway, remove stray sessions (every 5 min)
php artisan orders:expire-pending   # D81: cancel unconfirmed orders past their store's pending_expiry_hours (every 15 min)
php artisan pricing:promotions-tick # D90: move promotions scheduled → active → ended at their boundaries (every minute)
```

---

## 15. Endpoint Count

| Area           | Endpoints |
| -------------- | --------: |
| Store public   |         7 |
| Store auth     |        10 |
| Store customer |        16 |
| Dashboard      |       115 |
| Webhooks       |         2 |
| **Total**      |   **150** |

---

## 16. Build Order

1. Tenancy: host middleware, settings, tenant users, CLI, Sanctum guards.
2. Catalog: units, categories, fields + vertical seeders, products + SKU, images, relations.
3. Pricing: resolver, tiers, bulk pricing.
4. Inventory: adjust (incl. BR-INV08), movements.
5. Messaging (Hypersender WhatsApp + SMS, Task 4), then Customers: OTP, register/login, invitations, addresses, cart.
6. Orders: checkout transaction, transitions, cancel, history, notifications.
7. Collections and reports.
8. Storefront UI, then dashboard UI (both Next.js, RTL, mobile-first).
