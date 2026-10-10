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
| 403  | `forbidden`                | a policy denied the action                                |
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
| 422  | `cover_price_required`     | BR-PR10: a % price on a SKU without a cover price         |
| 403  | `prices_hidden`            | guest add-to-cart while `require_login_for_prices` (D53)  |
| 422  | `cart_line_invalid`        | a cart line below MOQ, above stock, or of an inactive product (`items[]` filled) |
| 422  | `cart_empty`               | checkout with an empty cart (D57)                         |
| 409  | `invalid_transition`       | order status change not allowed (`details.from/to`, BR-O01/O02) |
| 409  | `has_collections`          | cancelling an order with collected money (BR-O03, `details.collected_amount`) |
| 409  | `address_locked`           | editing the delivery address from `out_for_delivery` on (D9) |
| 422  | `exceeds_balance`          | a collection above the order's remaining balance (BR-C03, `details.remaining`) |
| 409  | `order_cancelled`          | recording a collection on a cancelled order (BR-C04)      |
| 409  | `already_voided`           | voiding a collection twice (BR-C02, `details.voided_at`)  |
| 409  | `idempotency_in_progress`  | same `Idempotency-Key` while the first request still runs (D55) |
| 422  | `idempotency_key_reused`   | same `Idempotency-Key` with a different body (D55)        |
| 429  | `rate_limited`             | OTP / login throttling                                    |
| 503  | `store_unavailable`        | tenant suspended (storefront)                             |
| 500  | `server_error`             | unexpected failure (no internals unless `app.debug`)      |

The complete list (also `otp_*`, `invalid_credentials`, `insufficient_stock`, `invalid_phone`, `category_cycle`, `gallery_full`, …) is the `ErrorCode` enum in `backend/openapi/openapi.yaml`.

### Common rules

- All text is Arabic; dates ISO-8601 UTC; money as string `"150.00"`.
- Lists: `?page=`, `?per_page=` (max 100), `?sort=field|-field`.
- Every mutating dashboard endpoint writes an audit record (BR-L).
- `Idempotency-Key` header is honored on `POST /api/store/checkout` and on all `POST /api/dashboard/orders/{id}/...` transitions (24 h window).

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

Full active tree: `[{ id, name, slug, image_url, children: [...] }]`.

### `GET /api/store/filters`

Facet definitions in `filter_order`, with options and counts for the **current selection**.

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
    "available": 140,
    "in_stock": true
  }
}
```

If `require_login_for_prices` is on and no customer session: `sku.cover_price/base_price/tiers/discount_percent` are omitted and `prices_hidden: true` is added at the product level. `coming_soon` products return `in_stock:false, coming_soon:true` and `available` omitted.

Delivered in stages (Task 1 = Catalog): the item also carries `description`; `custom_fields` lists only active fields with `show_on_storefront`, select values as the option label and booleans as `نعم`/`لا`. Inventory (Task 3): `available` is the SKU's stock in base units and `in_stock` is true when the product is `active` and at least one selling unit is available (`available ≥ conversion_factor`, D37). Pricing (Task 2): `tiers` are `{ min_quantity, unit_price }` lowest first; `discount_percent` is the % the base price was entered as, else the % it implies off `cover_price`, else `null` (no cover price). Filter facets with `type: boolean` use the option ids `"true"` / `"false"`.

### `GET /api/store/products/{slug}`

Product detail + `gallery: []` + `related: [{ type: "revision_of"|"companion", product: {…list shape…} }]` (BR-P10).

Detail notes (Task 1): `gallery` items are `{ id, url, is_main, sort_order }`; `related` items are `{ type, direction: "outgoing"|"incoming", product }` where `outgoing` means this product declared the relation (e.g. this is the revision of `product`). Relations to inactive products are not returned. An inactive or unknown slug is `404`.

### `GET /api/store/price-list`

Same filters as the product list; returns all matching products (no pagination, max 2000) in a compact shape for the printable page (BR-P13).

Row: `{ id, name, slug, code, unit, moq, cover_price, base_price, availability: "available"|"coming_soon" }`; `meta: { count, truncated, prices_hidden }`. Prices follow the same visibility rule as the product list.

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
- `invitation/{token}`: unknown, used, expired, superseded (re-sent) or no-longer-invited → `404 not_found`. `phone_masked` looks like `+20101****678`.
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
| POST   | `/api/store/cart/items`          | `sku_id, quantity` | upsert; validates integer, `≥ moq`, `≤ available`, product active                                     |
| PATCH  | `/api/store/cart/items/{sku_id}` | `quantity`         |                                                                                                       |
| DELETE | `/api/store/cart/items/{sku_id}` |                    |                                                                                                       |
| DELETE | `/api/store/cart`                |                    | clear                                                                                                 |

Cart response:

```json
{
  "data": {
    "items": [
      {
        "sku_id": 501,
        "product_name": "...",
        "unit": "كرتونة",
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

Delivered in Task 5: every cart write answers this same body. `quantity`, `moq` are in selling units, `available` in base units (D37). A line that cannot be ordered is `422 cart_line_invalid` with `items: [{ sku_id, reason: below_moq|insufficient_stock|product_inactive, moq?|available? }]` (`available` here in selling units). Unknown or another tenant's SKU, or a SKU not in the cart for PATCH/DELETE → `404`. `mov.enabled` is false when the setting is on without an amount. Guest `POST /cart/items`: `403 prices_hidden` when `require_login_for_prices` is on, else `401` (D53).

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

---

## 4. Dashboard — Auth

| Method | Path                         | Body              | Answer |
| ------ | ---------------------------- | ----------------- | ------ |
| POST   | `/api/dashboard/auth/login`  | `email, password` | `{ data: { id, name, email, role, status, last_login_at } }` |
| POST   | `/api/dashboard/auth/logout` |                   | `204`  |
| GET    | `/api/dashboard/me`          |                   | `{ data: { user: { id, name, email, role, status, last_login_at }, tenant: { name, subdomain, status, vertical } } }` |

`login` errors: `422 invalid_credentials`, `403 user_disabled`, `429 rate_limited` after 5 failures per tenant+email+IP per minute, `400 stateful_request_required` without a SPA origin. Suspended tenant → `403 tenant_suspended`. The email is matched case-insensitively.

---

## 5. Dashboard — Settings (BR-M)

| Method | Path                           | Notes                                       |
| ------ | ------------------------------ | ------------------------------------------- |
| GET    | `/api/dashboard/settings`      |                                             |
| PUT    | `/api/dashboard/settings`      | all fields of `tenant_settings`; audited    |
| POST   | `/api/dashboard/settings/logo` | multipart; also `/favicon`                  |
| GET    | `/api/dashboard/users`         | tenant users                                |
| POST   | `/api/dashboard/users`         | `name, email, password`; role fixed `owner` |
| PATCH  | `/api/dashboard/users/{id}`    | `status`, `password`                        |

---

## 6. Dashboard — Catalog

### Categories

| Method | Path                                     | Notes                                                |
| ------ | ---------------------------------------- | ---------------------------------------------------- |
| GET    | `/api/dashboard/categories`              | tree incl. archived (`?status=`)                     |
| POST   | `/api/dashboard/categories`              | `name, parent_id?, image?, sort_order?`              |
| PUT    | `/api/dashboard/categories/{id}`         | `name, parent_id` (present, null = root), `sort_order?`; move = change `parent_id`; cycle → `422 category_cycle` |
| POST   | `/api/dashboard/categories/{id}/archive` | allowed with products (BR-P06); products stay linked |
| POST   | `/api/dashboard/categories/{id}/restore` |                                                      |
| POST   | `/api/dashboard/categories/reorder`      | `[ {id, sort_order} ]`                               |

### Units

`GET/POST /api/dashboard/units`, `PUT /api/dashboard/units/{id}`, `POST .../archive`. Archive rejected (`409 in_use`) while any SKU uses the unit.

### Custom fields (BR-P08)

| Method | Path                                                    | Notes                                                                                                    |
| ------ | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| GET    | `/api/dashboard/fields`                                 | definitions + options                                                                                    |
| POST   | `/api/dashboard/fields`                                 | `key, label, type, is_required, show_on_storefront, is_filterable, filter_order, sort_order, options[]?` |
| PUT    | `/api/dashboard/fields/{id}`                            | `type` change rejected when values exist (`409`)                                                         |
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
    "selling_unit_id": 2,
    "base_unit_id": 1,
    "conversion_factor": 20,
    "cover_price": "120.00",
    "base_price": "90.00",
    "base_price_percent": null,
    "moq": 5,
    "tiers": [{ "min_quantity": 10, "unit_price": "85.00" }]
  },
  "opening_stock": 100
}
```

`base_price` **or** `base_price_percent` is required (BR-PR10); when percent is given, server computes the price and stores both (the percent wins if both are sent). Same for each tier (`unit_price` or `discount_percent`). On update, a body without `sku.tiers` keeps the tiers (D34). The detail response carries `sku.tiers: [{ min_quantity, unit_price, discount_percent }]`. Prices, MOQ and tiers are audited as `sku.price_changed` / `sku.moq_changed` / `sku.tiers_changed`, not inside `product.updated`. `opening_stock` is accepted only on create and writes an `opening` movement.

### Pricing (BR-PR11)

| Method | Path                                             | Body                                                                                         |
| ------ | ------------------------------------------------ | -------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| PUT    | `/api/dashboard/products/{id}/pricing`           | `{ cover_price?, base_price                                                                  | base_price_percent, moq, tiers: [...] }` replaces tiers |
| POST   | `/api/dashboard/pricing/bulk`                    | `{ filter: {...}, base_price_percent, tiers: [ { min_quantity, discount_percent } ], moq? }` |
| POST   | `/api/dashboard/pricing/bulk/preview`            | same body → `{ affected, skipped_no_cover_price: [...] }` without writing                    |
| POST   | `/api/dashboard/products/{id}/pricing/recompute` | recompute prices from stored percentages after a cover-price change                          |

Delivered (Task 2):

- `PUT …/pricing` and `…/recompute` return the SKU price set: `{ product_id, sku_id, code, cover_price, base_price, base_price_percent, discount_percent, moq, tiers: [{ min_quantity, unit_price, discount_percent }] }`. A missing `cover_price` keeps the current one (D34); `tiers` is required and replaces every tier (`[]` removes them). Errors: `422 invalid_tiers`, `422 cover_price_required`, `404` for another tenant's product.
- `POST /pricing/bulk` returns `{ affected, skipped_no_cover_price: [{ product_id, sku_id, code, name }], batch_id }`; `…/bulk/preview` returns the same without `batch_id` and writes nothing. Bulk tiers are `{ min_quantity, discount_percent }` only. An empty filter is `422` on `filter`.
- Audit: `sku.price_changed`, `sku.tiers_changed`, `sku.moq_changed` (per SKU, only what changed) and `pricing.bulk_applied` (one per bulk, same `batch_id`).

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

`transition` rules (BR-O01): target must be forward of current; `delivered` triggers inventory deduction and, with `collect_full_amount: true`, creates one collection for the remaining balance in the same transaction (BR-C04). Invalid → `409 invalid_transition` with `{ "details": { "from": "preparing", "to": "confirmed" } }`.

Delivered in Task 6: the dashboard order is the store shape plus `id`, `customer { id, name, phone }`, `confirmed_at`, `delivered_at`, `cancelled_at`, `is_stale` (pending past `stale_pending_hours`), history entries with `actor_id` and `note`, and on the detail `collections` (the collection shape of §10, newest first, voided ones included). `?from=&to=` are `Y-m-d` days of `placed_at`, both inclusive; `?q=` matches the order number (`#1024` too), customer name or phone, or recipient name; `?sort=placed_at|number|order_total` (default `-placed_at`). `delivery-address` takes `governorate, city, address_line, label?, landmark?, name?, phone?` (name/phone kept when omitted) → `409 address_locked` from `out_for_delivery` on. `collect_full_amount` is ignored for other targets (see §10 for what it records). Every write answers the dashboard order detail. Audit: `order.status_changed`, `order.cancelled`, `order.delivery_address_changed`.

---

## 10. Dashboard — Collections (BR-C02..C04)

| Method | Path                                         | Body                                                                        |
| ------ | -------------------------------------------- | --------------------------------------------------------------------------- |
| GET    | `/api/dashboard/collections`                 | `?from=&to=&customer_id=&order=`                                            |
| POST   | `/api/dashboard/orders/{number}/collections` | `{ amount, collected_at?, note? }`; `422 exceeds_balance` if over remaining |
| POST   | `/api/dashboard/collections/{id}/void`       | `{ reason }`                                                                |
| GET    | `/api/dashboard/customers/{id}/statement`    | orders with totals, collected, outstanding (basis for V2 statements)        |

Delivered in Task 7:

- **Collection** shape (list, record, void, and `collections` on the dashboard order detail): `{ id, order { id, number, order_total, collected_amount, collection_status }, customer { id, name }, amount, method: "cash", method_label, collected_at, recorded_by, note, is_voided, voided_at, void_reason, voided_by, created_at }`. `order` carries the balance after the change. `customer` is only on the list, not on the order detail. `recorded_by`/`voided_by` are tenant user ids (`null` = system).
- **List**: every collection, voided ones included, newest `collected_at` first. `from`/`to` are business days (D60) of `collected_at`, both inclusive. `order` is the order number.
- **Record**: answers `201` with the collection. `amount` is `decimal:0,2`, `> 0`, ≤ 9 999 999 999.99. `collected_at` defaults to now and cannot be in the future. `note` is at most 300 characters. Allowed at any order status but cancelled (`409 order_cancelled`); a deposit before delivery is fine (BR-C04). More than the remaining balance is `422 exceeds_balance`. The order row is locked, so two concurrent collections cannot both fit one balance. Honors `Idempotency-Key`.
- **Void**: `reason` is at most 300 characters. Answers the collection; a second void is `409 already_voided`. Voiding everything makes the order cancellable again (BR-O03). Honors `Idempotency-Key`.
- `orders.collected_amount` / `collection_status` are recomputed after every record or void (BR-C03).
- **Shortcut**: `transition` to `delivered` with `collect_full_amount: true` records one collection for whatever is left, in the same transaction. Nothing is recorded when a deposit already covered the order; if the collection fails, the delivery rolls back.
- **Statement**: `{ data: [ { number, status, status_label, placed_at, delivered_at, order_total, collected_amount, balance, collection_status } ], links, meta, customer { id, name, phone }, summary { orders_count, total, collected, outstanding } }`.
  - Lists the customer's non-cancelled orders, newest first.
  - `balance` = `order_total − collected_amount`.
  - `summary.outstanding` counts delivered orders only (D59), the same figure as the customer list's `outstanding_amount`.
- Audit actions: `collection.recorded`, `collection.voided`.

---

## 11. Dashboard — Reports (D5)

| Method | Path                                 | Returns                                                                                    |
| ------ | ------------------------------------ | ------------------------------------------------------------------------------------------ | ---- | -------------------------- |
| GET    | `/api/dashboard/reports/overview`    | counts per order status, today's orders, stale pending, outstanding total, low-stock count |
| GET    | `/api/dashboard/reports/sales`       | `?from=&to=&group=day                                                                      | week | month` → totals per bucket |
| GET    | `/api/dashboard/reports/outstanding` | per customer: orders, total, collected, outstanding                                        |
| GET    | `/api/dashboard/reports/low-stock`   | SKUs with `available ≤ moq` (threshold param optional)                                     |

Delivered in Task 7. All days are business days in Africa/Cairo (D60).

- **overview**:
  - Shape: `{ orders_by_status { pending, confirmed, preparing, out_for_delivery, delivered, cancelled }, today { orders, total }, stale_pending, outstanding_total, low_stock_count }`.
  - `today` counts orders placed since local midnight; its total leaves cancelled orders out.
  - `outstanding_total` follows D59.
- **sales**:
  - Shape: `{ from, to, group, buckets: [ { period_start, orders, total } ], totals { orders, total } }`.
  - It covers delivered orders by `delivered_at`.
  - Every bucket of the range is listed, with empty ones as zero. Weeks start on Saturday (D58) and the first bucket starts on the Saturday on or before `from`.
  - Without `from`/`to`, it covers the last 30 days up to today; `group` defaults to `day`.
- **outstanding**:
  - Paginated `{ customer { id, name, phone }, orders, total, collected, outstanding }` rows, largest debt first.
  - It covers delivered orders that are not fully collected (D59).
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

Delivered in Task 6 (notifications): newest first, `{ id, type: new_order|customer_cancelled, type_label, details: { order_number, customer_name, order_total, reason? }, read_at, created_at }` plus top-level `unread_count` next to `meta` (`details`, not `data`, so the item does not collide with the envelope). `read` answers `{ data: { marked } }`; ids of another tenant or already read are skipped; audited `notifications.read`.

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

Webhooks are platform-level (not tenant hosts): they are served only on `https://hooks.{base_domain}/...` (`hooks.gomal.test` locally) and locate the tenant through the message (`provider_message_id`, `provider_request_id`, or our `uuid` sent as the SMS `request_id`).

- **Auth:** Hypersender has no webhook signature. Each instance is configured in the Hypersender dashboard to send `Authorization: <MESSAGING_WEBHOOK_SECRET>` (`Bearer <secret>` also accepted); anything else is `401 unauthenticated`, and an empty secret refuses every call.
- **Body:** `{ event, data }` as Hypersender sends it; `422 validation_failed` without them.
- **Responses:** `200 { "data": { "received": true } }`; events that are not delivery statuses (heartbeats, incoming messages) are acknowledged and dropped; a status for a message we never sent is `404 not_found`. Processing is queued (`ProcessProviderWebhook`) and idempotent: a final message ignores replays.

---

## 14. Platform CLI (not HTTP, Tech §18)

```
php artisan tenant:create alnoor "مكتبة النور" --owner-email=... --owner-password=... --vertical=school-books
php artisan tenant:suspend alnoor
php artisan tenant:activate alnoor
php artisan tenant:list
php artisan api:openapi          # compile backend/openapi/openapi.yaml → backend/openapi.json (Task 8)
```

---

## 15. Endpoint Count

| Area           | Endpoints |
| -------------- | --------: |
| Store public   |         6 |
| Store auth     |        10 |
| Store customer |        13 |
| Dashboard      |        71 |
| Webhooks       |         2 |
| **Total**      |   **102** |

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
