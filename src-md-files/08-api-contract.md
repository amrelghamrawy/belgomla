# BelGomla E-commerce — API Contract (V1)

REST API served by Laravel under the tenant host: `https://{tenant}.gomalecommerce.app/api/...` (Tech §5). Tenant is resolved from `Host` only.

---

## 0. Conventions

### Base paths

| Prefix               | Audience                       | Guard             |
| -------------------- | ------------------------------ | ----------------- |
| `/api/store/...`     | Storefront (public + customer) | none / `customer` |
| `/api/dashboard/...` | Merchant dashboard             | `tenant_user`     |
| `/api/webhooks/...`  | Providers (Meta, SMS Misr)     | signature         |

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
| 401  | `unauthenticated`          | no/invalid session, tenant mismatch                       |
| 403  | `forbidden`                | customer not `active`, tenant suspended (dashboard)       |
| 404  | `not_found`                | missing **or belongs to another tenant** (BR-T04)         |
| 409  | `conflict`                 | invalid state transition, duplicate code/phone            |
| 422  | `validation_failed`        | field validation                                          |
| 422  | `checkout_failed`          | one or more checkout assertions failed (`items[]` filled) |
| 422  | `inventory_below_reserved` | BR-INV08 (`details.orders[]` filled)                      |
| 429  | `rate_limited`             | OTP / login throttling                                    |
| 503  | `store_unavailable`        | tenant suspended (storefront)                             |

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
{ "data": { "name": "مكتبة النور", "logo_url": "...", "favicon_url": "...", "colors": {"primary":"#0A5","secondary":"#FFF"},
  "phone": "+20...", "whatsapp": "+20...", "address": "...", "description": "...", "working_hours": [...],
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

If `require_login_for_prices` is on and no customer session: `sku.cover_price/base_price/tiers/discount_percent` are omitted and `prices_hidden: true` is added. `coming_soon` products return `in_stock:false, coming_soon:true` and `available` omitted.

### `GET /api/store/products/{slug}`

Product detail + `gallery: []` + `related: [{ type: "revision_of"|"companion", product: {…list shape…} }]` (BR-P10).

### `GET /api/store/price-list`

Same filters as the product list; returns all matching products (no pagination, max 2000) in a compact shape for the printable page (BR-P13).

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

---

## 3. Storefront — Customer (guard `customer`, status `active`)

### Addresses

| Method | Path                        | Notes                                                                    |
| ------ | --------------------------- | ------------------------------------------------------------------------ |
| GET    | `/api/store/addresses`      |                                                                          |
| POST   | `/api/store/addresses`      | `label, governorate, city, address_line, landmark?, phone?, is_default?` |
| PUT    | `/api/store/addresses/{id}` |                                                                          |
| DELETE | `/api/store/addresses/{id}` | archives                                                                 |

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

---

## 4. Dashboard — Auth

| Method | Path                         | Body              |
| ------ | ---------------------------- | ----------------- |
| POST   | `/api/dashboard/auth/login`  | `email, password` |
| POST   | `/api/dashboard/auth/logout` |                   |
| GET    | `/api/dashboard/me`          |                   |

Suspended tenant → `403 tenant_suspended`.

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
| PUT    | `/api/dashboard/categories/{id}`         | move = change `parent_id`; cycle → `422`             |
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
| POST   | `/api/dashboard/products/{id}/images/reorder`    |                                                                                |
| GET    | `/api/dashboard/products/{id}/relations`         |                                                                                |
| POST   | `/api/dashboard/products/{id}/relations`         | `related_product_id, type`                                                     |
| DELETE | `/api/dashboard/products/{id}/relations/{relId}` |                                                                                |
| POST   | `/api/dashboard/products/bulk/status`            | `{ filter: {...same as list...}, status }` → `{ affected, batch_id }` (BR-P12) |

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

`base_price` **or** `base_price_percent` is required (BR-PR10); when percent is given, server computes the price and stores both. Same for each tier (`unit_price` or `discount_percent`). `opening_stock` is accepted only on create and writes an `opening` movement.

### Pricing (BR-PR11)

| Method | Path                                             | Body                                                                                         |
| ------ | ------------------------------------------------ | -------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| PUT    | `/api/dashboard/products/{id}/pricing`           | `{ cover_price?, base_price                                                                  | base_price_percent, moq, tiers: [...] }` replaces tiers |
| POST   | `/api/dashboard/pricing/bulk`                    | `{ filter: {...}, base_price_percent, tiers: [ { min_quantity, discount_percent } ], moq? }` |
| POST   | `/api/dashboard/pricing/bulk/preview`            | same body → `{ affected, skipped_no_cover_price: [...] }` without writing                    |
| POST   | `/api/dashboard/products/{id}/pricing/recompute` | recompute prices from stored percentages after a cover-price change                          |

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

---

## 8. Dashboard — Customers

| Method | Path                                    | Notes                                                    |
| ------ | --------------------------------------- | -------------------------------------------------------- |
| GET    | `/api/dashboard/customers`              | `?q=&status=`; includes `outstanding_amount`             |
| POST   | `/api/dashboard/customers`              | `name, phone, addresses[]?` → status `invited` (BR-CU08) |
| GET    | `/api/dashboard/customers/{id}`         | profile, addresses, recent orders, outstanding           |
| PUT    | `/api/dashboard/customers/{id}`         | name, addresses                                          |
| POST   | `/api/dashboard/customers/{id}/invite`  | sends/re-sends invitation → `{ sent_via, expires_at }`   |
| POST   | `/api/dashboard/customers/{id}/approve` | `pending_approval → active`                              |
| POST   | `/api/dashboard/customers/{id}/block`   | `reason`                                                 |
| POST   | `/api/dashboard/customers/{id}/unblock` |                                                          |

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

---

## 10. Dashboard — Collections (BR-C02..C04)

| Method | Path                                         | Body                                                                        |
| ------ | -------------------------------------------- | --------------------------------------------------------------------------- |
| GET    | `/api/dashboard/collections`                 | `?from=&to=&customer_id=&order=`                                            |
| POST   | `/api/dashboard/orders/{number}/collections` | `{ amount, collected_at?, note? }`; `422 exceeds_balance` if over remaining |
| POST   | `/api/dashboard/collections/{id}/void`       | `{ reason }`                                                                |
| GET    | `/api/dashboard/customers/{id}/statement`    | orders with totals, collected, outstanding (basis for V2 statements)        |

---

## 11. Dashboard — Reports (D5)

| Method | Path                                 | Returns                                                                                    |
| ------ | ------------------------------------ | ------------------------------------------------------------------------------------------ | ---- | -------------------------- |
| GET    | `/api/dashboard/reports/overview`    | counts per order status, today's orders, stale pending, outstanding total, low-stock count |
| GET    | `/api/dashboard/reports/sales`       | `?from=&to=&group=day                                                                      | week | month` → totals per bucket |
| GET    | `/api/dashboard/reports/outstanding` | per customer: orders, total, collected, outstanding                                        |
| GET    | `/api/dashboard/reports/low-stock`   | SKUs with `available ≤ moq` (threshold param optional)                                     |

---

## 12. Dashboard — Notifications & Audit

| Method | Path                                | Notes                                                    |
| ------ | ----------------------------------- | -------------------------------------------------------- |
| GET    | `/api/dashboard/notifications`      | `?unread=1`                                              |
| POST   | `/api/dashboard/notifications/read` | `{ ids: [] }` or `{ all: true }`                         |
| GET    | `/api/dashboard/audit`              | `?entity_type=&entity_id=&actor_id=&from=&to=&batch_id=` |

---

## 13. Webhooks (Tech §13)

| Method | Path                     | Notes                                                                                                                  |
| ------ | ------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| GET    | `/api/webhooks/whatsapp` | Meta verification challenge                                                                                            |
| POST   | `/api/webhooks/whatsapp` | delivery statuses; `X-Hub-Signature-256` verified; on `failed` with "not a WhatsApp user" error → enqueue SMS fallback |
| POST   | `/api/webhooks/sms-misr` | delivery report; shared-secret verified                                                                                |

Webhooks are platform-level (not tenant hosts): they are served on `https://hooks.gomalecommerce.app/...` and locate the tenant through `outbound_messages.provider_message_id`.

---

## 14. Platform CLI (not HTTP, Tech §18)

```
php artisan tenant:create alnoor "مكتبة النور" --owner-email=... --owner-password=... --vertical=school-books
php artisan tenant:suspend alnoor
php artisan tenant:activate alnoor
php artisan tenant:list
```

---

## 15. Endpoint Count

| Area           | Endpoints |
| -------------- | --------: |
| Store public   |         6 |
| Store auth     |        10 |
| Store customer |        13 |
| Dashboard      |        71 |
| Webhooks       |         3 |
| **Total**      |   **103** |

---

## 16. Build Order

1. Tenancy: host middleware, settings, tenant users, CLI, Sanctum guards.
2. Catalog: units, categories, fields + vertical seeders, products + SKU, images, relations.
3. Pricing: resolver, tiers, bulk pricing.
4. Inventory: adjust (incl. BR-INV08), movements.
5. Customers: OTP + Messaging service (WhatsApp/SMS Misr), register/login, invitations, addresses, cart.
6. Orders: checkout transaction, transitions, cancel, history, notifications.
7. Collections and reports.
8. Storefront UI, then dashboard UI (both Next.js, RTL, mobile-first).
