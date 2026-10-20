# V2 Task 10 — Pre-orders on Coming Soon products

Status: **done** (2026-10-20, commit: see log). A Coming Soon product the merchant opens to pre-orders (`preorder_enabled`, `expected_at`, `preorder_limit`) is booked from the storefront as a pre-order line in the cart and confirmed on its own (`POST /api/store/checkout/preorder`): an order of `kind = preorder`, pending, holding no stock, checked for MOQ, the cap (under the product lock) and the credit limit, no MOV; `new_preorder` bell. It is never confirmed, edited, discounted, collected, stale or expired; the customer or merchant cancels it. Once the product is active and stocked, `ConvertPreorders` (`POST /api/dashboard/products/{id}/convert-preorders`, `orders.manage`, idempotent) turns open pre-orders into normal orders oldest first as far as the stock goes (`converted_from_id`, today's tiers, reserved), keeps the rest (`converted_quantity`, `converted` line changes) and closes a pre-order with nothing left (`converted_at`); the customer gets the `preorder_converted` WhatsApp text. `PreorderDemandQuery` (`GET …/preorder-demand`, the status answer's `meta.preorder_demand`, the storefront's `preorder.remaining`) and `GET /api/dashboard/preorders`. Frontend: «احجز الآن» on the card and product page with the expected day and «السعر النهائي عند التوفر», the cart's pre-order section and `/checkout/preorder`, the pre-orders tab and pre-order detail in `/account/orders`, the product form's pre-order settings, the product page's demand box and «تحويل الحجوزات إلى طلبات» with the summary, the orders list kind filter and badge, the bell. D88; D21 resolved. Requires V2 Tasks 07, 09. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Schema:** besides the planned columns, `orders.converted_at` (a pre-order with nothing left) and `order_items.converted_quantity` (what of a pre-order line is already in a normal order) carry the conversion state; `order_item_changes.change` adds `converted`; `notifications.type` adds `new_preorder`. CHECKs: `converted_from_id` only on orders, `converted_at` only on pre-orders, `converted_quantity` within the line and 0 on reserved lines.
- **A converted pre-order keeps status `pending`** (the task keeps `OrderStatus` unchanged); its derived `preorder_state` (`open` / `converted` / `cancelled`) is what the API and UI show. Its total follows what is left (0 once converted), so it weighs nothing in the credit exposure; it cannot be cancelled once converted in full.
- **No separate `CancelPreorder` action:** `CancelOrder` handles both kinds; the state machine refuses every other transition of a pre-order and the release only touches reserved lines.
- **The cap** is a `checkout_failed` item (`preorder_limit` with `requested`, `remaining`) rather than a new error code, so the storefront shows it like any refused line; the concurrency test races two confirmations for the last units under the cap.
- **Conversion** skips the MOQ, the minimum order value and the credit limit (the pre-order met them) and only needs the lines orderable now (`CheckoutReport::assertConvertible`); it converts only the lines of the product being converted, and leaves pre-orders of customers who can no longer order untouched. Pre-orders count in the credit exposure at their remaining indicative total.
- **`TransitionProductStatus`** is the existing `SetProductStatus`: its answer carries `meta.preorder_demand` through a new Catalog contract (`PreorderDemand`, filled by Orders' `PreorderDemandQuery`, so Catalog does not depend on Orders). The dashboard shows the demand on the product page (`PreorderPanel`) rather than from the status answer.
- **Two new error codes:** `409 is_preorder` (collection or credit on a pre-order) and `409 product_not_active` (converting too early). Editing or discounting a pre-order answers `409 order_locked` with `details.kind = preorder`.
- **`preorder_converted`** is sent whatever `order_notify_statuses` says (like `placed`); the store may word it through `order_status_messages` (no editor in the settings screen yet).
- Pre-orders are left out of the overview counts and never appear among the orders holding a SKU.
- Not fixed here: on desktop the storefront header's category strip overflows the page horizontally when the store has many categories (pre-existing; the e2e checks the pre-order section fits instead).

## Goal

Publishers announce next year's editions months before they ship, and retailers want to secure quantities (BR-P04 note, D21). A customer can **pre-order** a `coming_soon` product. The pre-order holds no stock. When the product becomes active and stock arrives, the merchant converts pre-orders into normal orders, oldest first, as far as stock allows.

Out of scope: deposits/prepayment on pre-orders (cash collection on any order already works, BR-C04), automatic conversion without the merchant, pre-ordering products that are active but out of stock (that would be a backorder, BR-INV01).

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-P04 (coming soon), BR-INV01 (no backorders), BR-INV03, BR-PR06, BR-O01, BR-CU06 (one cart) |
| Tables    | `07-domain-model.md` §3 products/skus, §5 cart, §7 orders, §15 (pre-orders hook = `coming_soon`) |
| Decisions | D21, D37 (`in_stock` / `coming_soon` on the storefront) |

## Left by earlier tasks

- `ProductStatus::ComingSoon`, visible, `canTransitionTo` Active/Inactive. `CheckoutValidator` rejects non-active (`productInactive`). `StoreProductResource` → `coming_soon: true`, `in_stock: false`.
- `cart_items(cart_id, sku_id unique, quantity)`. `AddCartItem` refuses non-orderable SKUs.
- Task 07 `PlaceOrder` (lines from any source). Task 09 `EditOrderLines` and partial delivery. Task 03 notifications.

## Deliverables

### 1. Docs

D88 into 06; D21 marked resolved. BR-P04 is rewritten. BR-O14 is new (pre-orders: creation, no reservation, conversion FIFO, cancellation). 07 §3/§7 get the new columns. 08 gets the endpoints.

### 2. Schema

- `products.preorder_enabled boolean default false`, `products.expected_at date null` (shown on the storefront), `products.preorder_limit int null` (max total pre-ordered quantity in selling units; null = unlimited).
- `orders.kind varchar(10) default 'order'` CHECK `in ('order','preorder')`, `orders.converted_from_id bigint null` (a normal order created from a pre-order).
- `order_items.reserved boolean default true` (pre-order lines `false`). `ApplyTransition` and `CancelOrder` release only reserved lines.
- `cart_items.kind varchar(10) default 'order'`; unique becomes `(cart_id, sku_id, kind)`.

### 3. Module classes

- Catalog: `ProductData` gains the pre-order fields; storefront resources add `preorder{enabled, expected_at, remaining}`.
- Customers (cart): `AddCartItem` accepts `kind = preorder` only for a `coming_soon` product with `preorder_enabled`; `CartQuery` splits the lines by kind; the cart page shows two sections.
- Orders:
  - `Actions\PlacePreorder` (storefront: `POST /store/checkout/preorder`) → `PlaceOrder` with `kind = preorder`. It skips the stock check and reservation, but checks MOQ, `preorder_limit` (locked aggregate) and the credit limit. MOV does not apply to pre-orders (D88). It clears only the pre-order cart lines. The price shown is indicative: it is re-priced on conversion.
  - `OrderStatus` is unchanged: a pre-order stays `pending` and cannot be confirmed (`OrderStateMachine` refuses any transition except cancel for `kind = preorder`).
  - `Actions\ConvertPreorders::handle(Product, actorId)`: only when the product is `active`. It takes open pre-orders containing the product's SKU, oldest `placed_at` first. Per pre-order, in one transaction: lock the SKUs, then reserve what is available up to the ordered quantity per line, re-pricing at today's prices (`PriceResolver`). If at least one line has stock, create a normal order via `PlaceOrder` (`converted_from_id`) with the reservable quantities. The pre-order lines that were fully converted are marked converted, and partially converted ones keep the remainder on the pre-order. It notifies the customer (template `preorder_converted`). The merchant gets a summary.
  - `Actions\CancelPreorder` = `CancelOrder` for kind preorder (the customer may cancel any time, the merchant too).
  - `Queries\PreorderDemandQuery`: per product, the open pre-ordered quantity and the number of customers (feeds the merchant's purchase planning).
- `TransitionProductStatus` (coming_soon → active) shows the demand in the response; conversion stays an explicit merchant action.

### 4. Endpoints

| Method | Path | Notes |
| ------ | ---- | ----- |
| POST | `/api/store/checkout/preorder` | `idempotent` |
| GET  | `/api/dashboard/preorders?product_id&status` | list |
| GET  | `/api/dashboard/products/{id}/preorder-demand` | quantity, customers |
| POST | `/api/dashboard/products/{id}/convert-preorders` | `idempotent`, `permission:orders.manage` → `{converted_orders, partially, untouched}` |

### 5. Frontend

- Storefront product card/page for coming-soon products with pre-orders enabled: "احجز الآن" + the expected date + "السعر النهائي عند التوفر". The cart's pre-order section has its own "تأكيد الحجز" button.
- `/account/orders`: pre-orders tab with cancel.
- Dashboard product form: pre-order toggle, expected date, limit. Product page: demand box and "تحويل الحجوزات إلى طلبات" with the result summary. Orders list: kind filter.

## Test list

- `Unit/Actions/PlacePreorderTest`: BR-O14 no reservation; only coming_soon + enabled; MOQ; limit reached → 422 (concurrency test for the last unit of the limit); credit limit; MOV not applied (D88); only pre-order cart lines cleared.
- `Unit/Services/OrderStateMachineTest`: a pre-order can only be cancelled.
- `Unit/Actions/ConvertPreordersTest`: FIFO by `placed_at`; stock for 1.5 pre-orders → first converted fully, second partially, third untouched; re-priced at today's price; reservations made; `converted_from_id`; notifications; refuses while coming_soon.
- `Unit/Actions/CancelOrderTest`: a pre-order releases nothing.
- `Unit/Actions/AddCartItemTest`: kinds side by side.
- `Unit/Queries/PreorderDemandQueryTest`.
- `Http/Store/PreorderTest`, `Http/Dashboard/Orders/PreordersTest`.
- Frontend: card states, cart sections; e2e: pre-order → merchant activates + adds stock → converts → customer sees the order.

## Slices

1. Docs + schema.
2. Catalog fields + cart kinds.
3. `PlacePreorder`, state machine guard, cancel.
4. `ConvertPreorders`, demand query.
5. Endpoints + frontend.

## Definition of done

Green suites; curl through the whole cycle; docs updated.

## Open decisions (proposed)

- **D88** A pre-order is an order of `kind = preorder`: status pending, no stock reserved (BR-INV01 stays true), price indicative and re-priced at conversion, no MOV. Conversion is a merchant action after the product goes active, FIFO by placement time, partial when stock is short. The unconverted remainder stays as a pre-order until converted or cancelled.
